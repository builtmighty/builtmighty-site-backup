<?php
/**
 * Devcontainer manager — checks and updates .devcontainer config via GitHub API.
 *
 * Compares the repo's .devcontainer/devcontainer.json version against the
 * global template at builtmighty/.devcontainer and creates a PR to update
 * when needed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BM_Devcontainer_Manager {

	private const GLOBAL_OWNER = 'builtmighty';
	private const GLOBAL_REPO  = '.devcontainer';
	private const GLOBAL_REF   = 'main';
	private const API_BASE     = 'https://api.github.com';

	private BM_Backup_Settings $settings;

	public function __construct( BM_Backup_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register AJAX handlers.
	 */
	public function init(): void {
		add_action( 'wp_ajax_bm_backup_devcontainer_check', [ $this, 'ajax_check_version' ] );
		add_action( 'wp_ajax_bm_backup_devcontainer_update', [ $this, 'ajax_install_or_update' ] );
	}

	/**
	 * AJAX: Check the devcontainer version.
	 */
	public function ajax_check_version(): void {
		check_ajax_referer( 'bm_backup_nonce', 'nonce' );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! $this->is_authorized_user() ) {
			wp_send_json_error( 'Unauthorized' );
		}

		try {
			$result = $this->check_version();
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * AJAX: Install or update the devcontainer.
	 */
	public function ajax_install_or_update(): void {
		check_ajax_referer( 'bm_backup_nonce', 'nonce' );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! $this->is_authorized_user() ) {
			wp_send_json_error( 'Unauthorized' );
		}

		try {
			$result = $this->install_or_update();
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Check the repo's devcontainer version against the global template.
	 *
	 * @return array{status: string, current: ?string, latest: string}
	 */
	public function check_version(): array {
		$config = $this->get_github_config();

		// Fetch the global/latest version (public repo — no auth needed).
		$global = $this->api_get(
			self::API_BASE . '/repos/' . self::GLOBAL_OWNER . '/' . self::GLOBAL_REPO
				. '/contents/.devcontainer/devcontainer.json'
		);
		$latest_version = $this->extract_version_from_contents( $global );

		// Fetch the repo's current version.
		$repo_url = self::API_BASE . '/repos/' . $config['owner'] . '/' . $config['repo']
			. '/contents/.devcontainer/devcontainer.json';

		try {
			$repo_file       = $this->api_get( $repo_url );
			$current_version = $this->extract_version_from_contents( $repo_file );
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), '404' ) || str_contains( $e->getMessage(), 'Not Found' ) ) {
				return [
					'status'  => 'not_installed',
					'current' => null,
					'latest'  => $latest_version,
				];
			}
			throw $e;
		}

		if ( version_compare( $current_version, $latest_version, '>=' ) ) {
			return [
				'status'  => 'up_to_date',
				'current' => $current_version,
				'latest'  => $latest_version,
			];
		}

		return [
			'status'  => 'outdated',
			'current' => $current_version,
			'latest'  => $latest_version,
		];
	}

	/**
	 * Create a PR that installs or updates the .devcontainer directory.
	 *
	 * @return array{pr_url: string, branch: string}
	 */
	public function install_or_update(): array {
		$config  = $this->get_github_config();
		$version = $this->check_version();
		$latest  = $version['latest'];

		if ( $version['status'] === 'up_to_date' ) {
			throw new \RuntimeException( 'Devcontainer is already up to date (v' . $latest . ').' );
		}

		$owner = $config['owner'];
		$repo  = $config['repo'];

		// 1. Get the default branch.
		$repo_info      = $this->api_get( self::API_BASE . '/repos/' . $owner . '/' . $repo );
		$default_branch = $repo_info['default_branch'];

		// 2. Get HEAD SHA of the default branch.
		$ref      = $this->api_get( self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs/heads/' . $default_branch );
		$head_sha = $ref['object']['sha'];

		// 3. Create the update branch.
		$branch_name = 'devcontainer-update-' . $latest;
		try {
			$this->api_post(
				self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs',
				[
					'ref' => 'refs/heads/' . $branch_name,
					'sha' => $head_sha,
				]
			);
		} catch ( \RuntimeException $e ) {
			if ( str_contains( $e->getMessage(), '422' ) || str_contains( $e->getMessage(), 'Reference already exists' ) ) {
				throw new \RuntimeException(
					'Branch "' . $branch_name . '" already exists. A PR may already be open for this update.'
				);
			}
			throw $e;
		}

		// 4. Get the repo's current tree (recursive).
		$repo_tree_data = $this->api_get(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/trees/' . $head_sha . '?recursive=1'
		);
		$repo_tree = $repo_tree_data['tree'];

		// 5. Get the global template tree (recursive).
		$global_tree_data = $this->api_get(
			self::API_BASE . '/repos/' . self::GLOBAL_OWNER . '/' . self::GLOBAL_REPO
				. '/git/trees/' . self::GLOBAL_REF . '?recursive=1'
		);
		$global_tree = $global_tree_data['tree'];

		// 6. Build the new tree entries.
		$tree_items = [];

		// 6a. Collect existing .devcontainer/setup/* entries from the repo (preserve them).
		$repo_setup_entries = [];
		foreach ( $repo_tree as $entry ) {
			if ( $entry['type'] === 'blob' && str_starts_with( $entry['path'], '.devcontainer/setup/' ) ) {
				$repo_setup_entries[ $entry['path'] ] = true;
				$tree_items[] = [
					'path' => $entry['path'],
					'mode' => $entry['mode'],
					'type' => 'blob',
					'sha'  => $entry['sha'],
				];
			}
		}

		// 6b. Add all global template entries EXCEPT setup/*.
		$global_paths = [];
		foreach ( $global_tree as $entry ) {
			if ( $entry['type'] !== 'blob' ) {
				continue;
			}
			if ( ! str_starts_with( $entry['path'], '.devcontainer/' ) ) {
				continue;
			}
			if ( str_starts_with( $entry['path'], '.devcontainer/setup/' ) ) {
				continue;
			}
			$global_paths[ $entry['path'] ] = true;
			$tree_items[] = [
				'path' => $entry['path'],
				'mode' => $entry['mode'],
				'type' => 'blob',
				'sha'  => $entry['sha'],
			];
		}

		// 6c. Delete repo .devcontainer/* entries that are not in the global template
		//     and not in setup/ (they should be removed).
		foreach ( $repo_tree as $entry ) {
			if ( $entry['type'] !== 'blob' ) {
				continue;
			}
			if ( ! str_starts_with( $entry['path'], '.devcontainer/' ) ) {
				continue;
			}
			if ( str_starts_with( $entry['path'], '.devcontainer/setup/' ) ) {
				continue;
			}
			if ( isset( $global_paths[ $entry['path'] ] ) ) {
				continue;
			}
			// File exists in repo but not in global template — delete it.
			$tree_items[] = [
				'path' => $entry['path'],
				'mode' => $entry['mode'],
				'type' => 'blob',
				'sha'  => null,
			];
		}

		// 7. Create the new tree using base_tree so non-.devcontainer files are preserved.
		$new_tree = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/trees',
			[
				'base_tree' => $repo_tree_data['sha'],
				'tree'      => $tree_items,
			]
		);

		// 8. Create a commit on the new branch.
		$commit_message = 'Update .devcontainer to v' . $latest;
		$new_commit     = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/commits',
			[
				'message' => $commit_message,
				'tree'    => $new_tree['sha'],
				'parents' => [ $head_sha ],
			]
		);

		// 9. Point the branch to the new commit.
		$this->api_patch(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs/heads/' . $branch_name,
			[ 'sha' => $new_commit['sha'] ]
		);

		// 10. Create the pull request.
		$pr_body = "Updates the `.devcontainer` configuration to **v{$latest}** from the global template.\n\n"
			. "- `.devcontainer/setup/` has been preserved.\n"
			. "- All other `.devcontainer/` files have been replaced with the latest template.\n\n"
			. "Created by **BM Site Backup** plugin.";

		$pr = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/pulls',
			[
				'title' => 'Update .devcontainer to v' . $latest,
				'head'  => $branch_name,
				'base'  => $default_branch,
				'body'  => $pr_body,
			]
		);

		return [
			'pr_url' => $pr['html_url'],
			'branch' => $branch_name,
		];
	}

	/**
	 * Check whether the current user is authorised to manage the plugin.
	 */
	private function is_authorized_user(): bool {
		return bm_backup_is_authorized_user();
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Get the GitHub owner, repo, and PAT from settings with fallbacks.
	 *
	 * @return array{owner: string, repo: string, pat: string}
	 */
	private function get_github_config(): array {
		$owner = $this->settings->get( 'github_owner' );
		$repo  = $this->settings->get( 'github_repo' );
		$pat   = $this->settings->get_github_pat();

		// Fallbacks.
		if ( empty( $owner ) ) {
			$owner = 'builtmighty';
		}
		if ( empty( $repo ) ) {
			$repo = $this->settings->get( 'client_path' );
		}

		if ( empty( $repo ) ) {
			throw new \RuntimeException( 'Please configure the GitHub repository in the Devcontainer tab.' );
		}
		if ( empty( $pat ) ) {
			throw new \RuntimeException( 'Please save a GitHub Personal Access Token in the Devcontainer tab.' );
		}

		return [
			'owner' => $owner,
			'repo'  => $repo,
			'pat'   => $pat,
		];
	}

	/**
	 * Extract the version string from a GitHub Contents API response.
	 */
	private function extract_version_from_contents( array $response ): string {
		$content = base64_decode( $response['content'] ?? '' );
		if ( empty( $content ) ) {
			throw new \RuntimeException( 'Could not decode devcontainer.json content.' );
		}

		// The file has JS-style comments which json_decode cannot handle.
		// Strip single-line comments (// ...) before parsing.
		$content = preg_replace( '#^\s*//.*$#m', '', $content );

		$json = json_decode( $content, true );
		if ( ! is_array( $json ) || empty( $json['version'] ) ) {
			throw new \RuntimeException( 'devcontainer.json does not contain a "version" field.' );
		}

		return $json['version'];
	}

	/**
	 * Make an authenticated GET request to the GitHub API.
	 *
	 * @param string $url      Full API URL.
	 * @param bool   $use_auth Whether to include the Bearer token.
	 * @return array Decoded JSON response.
	 */
	private function api_get( string $url, bool $use_auth = true ): array {
		$args = [
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'BM-Site-Backup/' . BM_BACKUP_VERSION,
			],
			'timeout' => 30,
		];

		if ( $use_auth ) {
			$config = $this->get_github_config();
			$args['headers']['Authorization'] = 'Bearer ' . $config['pat'];
		}

		$response = wp_remote_get( $url, $args );

		return $this->handle_response( $response, $url );
	}

	/**
	 * Make an authenticated POST request to the GitHub API.
	 */
	private function api_post( string $url, array $body ): array {
		$config = $this->get_github_config();

		$response = wp_remote_post( $url, [
			'headers' => [
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . $config['pat'],
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'BM-Site-Backup/' . BM_BACKUP_VERSION,
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		return $this->handle_response( $response, $url );
	}

	/**
	 * Make an authenticated PATCH request to the GitHub API.
	 */
	private function api_patch( string $url, array $body ): array {
		$config = $this->get_github_config();

		$response = wp_remote_request( $url, [
			'method'  => 'PATCH',
			'headers' => [
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . $config['pat'],
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'BM-Site-Backup/' . BM_BACKUP_VERSION,
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		return $this->handle_response( $response, $url );
	}

	/**
	 * Process a wp_remote_* response and return the decoded body.
	 *
	 * @param array|\WP_Error $response The raw response.
	 * @param string          $url      The request URL (for error messages).
	 * @return array Decoded JSON body.
	 */
	private function handle_response( $response, string $url ): array {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'GitHub API request failed: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = $body['message'] ?? 'Unknown error';

			// Check for rate limiting.
			$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			if ( $code === 403 && $remaining === '0' ) {
				throw new \RuntimeException( 'GitHub API rate limit exceeded. Please wait and try again.' );
			}

			throw new \RuntimeException(
				sprintf( 'GitHub API error (%d): %s', $code, $message )
			);
		}

		return is_array( $body ) ? $body : [];
	}
}
