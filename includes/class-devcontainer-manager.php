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

class Mighty_Devcontainer_Manager {

	private const GLOBAL_OWNER = 'builtmighty';
	private const GLOBAL_REPO  = '.devcontainer';
	private const GLOBAL_REF   = 'main';
	private const API_BASE     = 'https://api.github.com';

	private Mighty_Backup_Settings $settings;

	public function __construct( Mighty_Backup_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register AJAX handlers.
	 */
	public function init(): void {
		add_action( 'wp_ajax_mighty_backup_devcontainer_check', [ $this, 'ajax_check_version' ] );
		add_action( 'wp_ajax_mighty_backup_devcontainer_update', [ $this, 'ajax_install_or_update' ] );
		add_action( 'wp_ajax_mighty_backup_push_bootstrap_secret', [ $this, 'ajax_push_bootstrap_secret' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_size_warning' ] );
		add_action( 'network_admin_notices', [ $this, 'maybe_show_size_warning' ] );

		// Auto-push BM_BOOTSTRAP_KEY whenever the bootstrap key changes
		// (api-key generation) or the GitHub config changes (settings save).
		add_action( 'mighty_backup_api_key_generated', [ $this, 'maybe_auto_push_bootstrap_secret' ] );
		add_action(
			'update_option_' . Mighty_Backup_Settings::OPTION_KEY,
			[ $this, 'on_single_site_settings_save' ],
			10,
			2
		);
		add_action(
			'update_site_option_' . Mighty_Backup_Settings::OPTION_KEY,
			[ $this, 'on_multisite_settings_save' ],
			10,
			3
		);
	}

	/**
	 * Hook adapter for `update_option_<option>`.
	 *
	 * @param mixed $old_value The previous saved settings array.
	 * @param mixed $new_value The newly saved settings array.
	 */
	public function on_single_site_settings_save( $old_value, $new_value ): void {
		$this->maybe_auto_push_on_settings_change(
			is_array( $old_value ) ? $old_value : [],
			is_array( $new_value ) ? $new_value : []
		);
	}

	/**
	 * Hook adapter for `update_site_option_<option>`.
	 *
	 * @param string $option    Option name.
	 * @param mixed  $new_value The newly saved settings array.
	 * @param mixed  $old_value The previous saved settings array.
	 */
	public function on_multisite_settings_save( $option, $new_value, $old_value ): void {
		$this->maybe_auto_push_on_settings_change(
			is_array( $old_value ) ? $old_value : [],
			is_array( $new_value ) ? $new_value : []
		);
	}

	/**
	 * Diff the github config across a settings save and fire an auto-push
	 * only when one of the relevant fields actually changed. This avoids
	 * a GitHub API roundtrip on every unrelated settings change (retention,
	 * schedule, etc.).
	 */
	private function maybe_auto_push_on_settings_change( array $old, array $new ): void {
		$keys = [ 'github_owner', 'github_repo', 'github_pat_enc' ];
		foreach ( $keys as $key ) {
			if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) {
				$this->maybe_auto_push_bootstrap_secret();
				return;
			}
		}
	}

	/**
	 * Push the bootstrap key to GitHub as a Codespaces secret if all
	 * preconditions are met (owner + repo + PAT configured, API key exists,
	 * libsodium available). Silent no-op when any precondition fails. All
	 * exceptions are caught and logged so this never blocks a settings save
	 * or key-generation flow.
	 *
	 * @return array|null The push result on success, null on no-op or failure.
	 */
	public function maybe_auto_push_bootstrap_secret(): ?array {
		$owner = (string) $this->settings->get( 'github_owner' );
		$repo  = (string) $this->settings->get( 'github_repo' );
		$pat   = (string) $this->settings->get_github_pat();

		if ( $owner === '' || $repo === '' || $pat === '' ) {
			return null;
		}

		if ( Mighty_Backup_Api_Endpoint::get_bootstrap_key() === '' ) {
			return null; // No API key generated yet.
		}

		if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
			return null; // Sodium unavailable — can't encrypt the secret.
		}

		try {
			$result = $this->push_bootstrap_secret();
			if ( class_exists( 'Mighty_Backup_Log_Stream' ) ) {
				Mighty_Backup_Log_Stream::add( sprintf(
					'Auto-pushed BM_BOOTSTRAP_KEY to %s/%s (%s).',
					$result['owner'],
					$result['repo'],
					! empty( $result['created'] ) ? 'created' : 'updated'
				) );
			}
			return $result;
		} catch ( \Throwable $e ) {
			if ( class_exists( 'Mighty_Backup_Log_Stream' ) ) {
				Mighty_Backup_Log_Stream::add( 'Auto-push of BM_BOOTSTRAP_KEY failed: ' . $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * AJAX: Check the devcontainer version.
	 */
	public function ajax_check_version(): void {
		check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

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
		check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

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
	 * AJAX: Push the BM_BOOTSTRAP_KEY value to the configured repo as a
	 * Codespaces secret.
	 */
	public function ajax_push_bootstrap_secret(): void {
		check_ajax_referer( 'mighty_backup_nonce', 'nonce' );

		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( ! $this->is_authorized_user() ) {
			wp_send_json_error( 'Unauthorized' );
		}

		try {
			$result = $this->push_bootstrap_secret();
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Show an admin warning on the settings page when the site exceeds 128 GB.
	 */
	public function maybe_show_size_warning(): void {
		// Only show on the Mighty Backup settings page.
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'mighty-backup' ) {
			return;
		}

		if ( ! $this->is_authorized_user() ) {
			return;
		}

		$disk_size = get_transient( 'mighty_backup_site_disk_size' );

		if ( $disk_size === false ) {
			return; // No cached size yet — will be computed on next devcontainer update.
		}

		$disk_size = (int) $disk_size;
		$max_bytes = 128 * 1024 * 1024 * 1024; // 128 GB.

		if ( $disk_size <= $max_bytes ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> &mdash; %s</p></div>',
			esc_html__( 'Mighty Backup: Site Too Large for Codespaces', 'mighty-backup' ),
			sprintf(
				/* translators: %s: human-readable site size */
				esc_html__( 'This site is %s (excluding uploads), which exceeds the 128 GB GitHub Codespace limit. The devcontainer has been configured with the maximum resources, but the Codespace may not have enough disk space.', 'mighty-backup' ),
				esc_html( size_format( $disk_size ) )
			)
		);
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
			// Missing version field → assume outdated.
			if ( str_contains( $e->getMessage(), 'does not contain a "version" field' ) ) {
				return [
					'status'  => 'outdated',
					'current' => null,
					'latest'  => $latest_version,
				];
			}
			throw $e;
		}

		if ( version_compare( $current_version, $latest_version, '>=' ) ) {
			// Version is current — also check if CPU tier is adequate for site size.
			$disk_size        = $this->calculate_site_disk_size();
			set_transient( 'mighty_backup_site_disk_size', $disk_size, DAY_IN_SECONDS );
			$tier             = $this->get_codespace_tier( $disk_size );
			$recommended_cpus = $tier ? $tier['cpus'] : 16;
			$current_cpus     = $this->extract_cpus_from_contents( $repo_file );
			$size_ok          = $current_cpus !== null && $current_cpus >= $recommended_cpus;

			return [
				'status'           => 'up_to_date',
				'current'          => $current_version,
				'latest'           => $latest_version,
				'size_ok'          => $size_ok,
				'current_cpus'     => $current_cpus,
				'recommended_cpus' => $recommended_cpus,
				'site_size'        => size_format( $disk_size ),
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
			// Version is current — check if storage size needs updating.
			if ( ! empty( $version['size_ok'] ) ) {
				throw new \RuntimeException( 'Devcontainer is already up to date (v' . $latest . ') with correct sizing.' );
			}
			return $this->create_size_update( $config, $version );
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

		// 6. Calculate site disk size and determine Codespace tier.
		$disk_size = $this->calculate_site_disk_size();
		$tier      = $this->get_codespace_tier( $disk_size );

		// Cache for the admin warning notice.
		set_transient( 'mighty_backup_site_disk_size', $disk_size, DAY_IN_SECONDS );

		// 7. Build the new tree entries.
		$tree_items = [];

		// 7a. Collect existing .devcontainer/setup/* entries from the repo (preserve them).
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

		// 7b. Add all global template entries EXCEPT setup/*.
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

			// Inject hostRequirements into devcontainer.json based on site disk size.
			if ( $entry['path'] === '.devcontainer/devcontainer.json' ) {
				$new_sha = $this->copy_blob_with_host_requirements( $entry['sha'], $owner, $repo, $tier );
			} else {
				$new_sha = $this->copy_blob_to_repo( $entry['sha'], $owner, $repo );
			}
			$tree_items[] = [
				'path' => $entry['path'],
				'mode' => $entry['mode'],
				'type' => 'blob',
				'sha'  => $new_sha,
			];
		}

		// 7c. Delete repo .devcontainer/* entries that are not in the global template
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

		// 8. Create the new tree using base_tree so non-.devcontainer files are preserved.
		$new_tree = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/trees',
			[
				'base_tree' => $repo_tree_data['sha'],
				'tree'      => $tree_items,
			]
		);

		// 9. Create a commit on the new branch.
		$commit_message = 'Update .devcontainer to v' . $latest;
		$new_commit     = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/commits',
			[
				'message' => $commit_message,
				'tree'    => $new_tree['sha'],
				'parents' => [ $head_sha ],
			]
		);

		// 10. Point the branch to the new commit.
		$this->api_patch(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs/heads/' . $branch_name,
			[ 'sha' => $new_commit['sha'] ]
		);

		// 11. Create the pull request.
		$pr_body = "Updates the `.devcontainer` configuration to **v{$latest}** from the global template.\n\n"
			. "- `.devcontainer/setup/` has been preserved.\n"
			. "- All other `.devcontainer/` files have been replaced with the latest template.\n";

		if ( $disk_size > 0 ) {
			$human_size = size_format( $disk_size );
			$cpus       = $tier ? $tier['cpus'] : 16;
			if ( $tier ) {
				$pr_body .= "- Configured for **{$cpus}-core** Codespace. Site size: {$human_size}.\n";
			} else {
				$pr_body .= "- **Warning:** Site size is {$human_size} (excluding uploads), which exceeds the 128 GB Codespace limit. Configured with maximum resources ({$cpus}-core).\n";
			}
		}

		$pr_body .= "\nCreated by **Mighty Backup** plugin.";

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
	 * Create a PR that only updates the hostRequirements cpus in devcontainer.json.
	 *
	 * Called when the devcontainer version is current but the site has outgrown
	 * the configured Codespace tier.
	 *
	 * @param array $config  GitHub config (owner, repo, pat).
	 * @param array $version Version check result with size info.
	 * @return array{pr_url: string, branch: string}
	 */
	private function create_size_update( array $config, array $version ): array {
		$owner = $config['owner'];
		$repo  = $config['repo'];

		$disk_size = $this->calculate_site_disk_size();
		$tier      = $this->get_codespace_tier( $disk_size );
		$cpus      = $tier ? $tier['cpus'] : 16;

		set_transient( 'mighty_backup_site_disk_size', $disk_size, DAY_IN_SECONDS );

		// 1. Get the default branch and HEAD SHA.
		$repo_info      = $this->api_get( self::API_BASE . '/repos/' . $owner . '/' . $repo );
		$default_branch = $repo_info['default_branch'];

		$ref      = $this->api_get( self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs/heads/' . $default_branch );
		$head_sha = $ref['object']['sha'];

		// 2. Create the update branch.
		$branch_name = 'devcontainer-resize-' . $cpus . 'core';
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
					'Branch "' . $branch_name . '" already exists. A PR may already be open for this resize.'
				);
			}
			throw $e;
		}

		// 3. Fetch the current devcontainer.json from the repo.
		$file_url = self::API_BASE . '/repos/' . $owner . '/' . $repo
			. '/contents/.devcontainer/devcontainer.json?ref=' . $default_branch;
		$file_response = $this->api_get( $file_url );

		$content = base64_decode( $file_response['content'] ?? '' );
		if ( empty( $content ) ) {
			throw new \RuntimeException( 'Could not decode existing devcontainer.json.' );
		}

		$stripped = preg_replace( '#^\s*//.*$#m', '', $content );
		$json     = json_decode( $stripped, true );
		if ( ! is_array( $json ) ) {
			throw new \RuntimeException( 'Could not parse existing devcontainer.json.' );
		}

		// 4. Update only the cpus field — preserve any other hostRequirements
		//    keys (memory, storage, etc.) that the existing file declares.
		if ( ! isset( $json['hostRequirements'] ) || ! is_array( $json['hostRequirements'] ) ) {
			$json['hostRequirements'] = [];
		}
		$json['hostRequirements']['cpus'] = $cpus;

		$new_content = wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		// 5. Create the updated blob.
		$new_blob = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/blobs',
			[
				'content'  => base64_encode( $new_content ),
				'encoding' => 'base64',
			]
		);

		// 6. Create a new tree with just the updated file.
		$repo_tree_data = $this->api_get(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/trees/' . $head_sha
		);

		$new_tree = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/trees',
			[
				'base_tree' => $repo_tree_data['sha'],
				'tree'      => [
					[
						'path' => '.devcontainer/devcontainer.json',
						'mode' => '100644',
						'type' => 'blob',
						'sha'  => $new_blob['sha'],
					],
				],
			]
		);

		// 7. Create a commit on the new branch.
		$human_size     = size_format( $disk_size );
		$commit_message = sprintf(
			'Resize devcontainer to %d-core — site is %s',
			$cpus,
			$human_size
		);

		$new_commit = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/commits',
			[
				'message' => $commit_message,
				'tree'    => $new_tree['sha'],
				'parents' => [ $head_sha ],
			]
		);

		// 8. Point the branch to the new commit.
		$this->api_patch(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/refs/heads/' . $branch_name,
			[ 'sha' => $new_commit['sha'] ]
		);

		// 9. Create the pull request.
		$current_cpus = $version['current_cpus'] ?? null;
		$pr_body = sprintf(
			"The site has grown to **%s** (excluding uploads). The current devcontainer "
			. "is configured for **%s-core** which is too small (needs 20%% headroom).\n\n"
			. "This PR updates `hostRequirements.cpus` to **%d** (%d-core = %d GB disk).\n\n"
			. "Created by **Mighty Backup** plugin.",
			$human_size,
			$current_cpus !== null ? $current_cpus : 'unknown',
			$cpus,
			$cpus,
			$this->cpus_to_disk_gb( $cpus )
		);

		$pr = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/pulls',
			[
				'title' => sprintf( 'Resize devcontainer to %d-core', $cpus ),
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
		return mighty_backup_is_authorized_user();
	}

	// ------------------------------------------------------------------
	// Private helpers
	// ------------------------------------------------------------------

	/**
	 * Copy a blob from the global template repo into the target repo.
	 *
	 * GitHub's Create Tree API requires blob SHAs to exist in the target
	 * repository. This fetches the blob content from the template and
	 * creates a matching blob in the target repo.
	 *
	 * @param string $sha   Blob SHA in the global template repo.
	 * @param string $owner Target repo owner.
	 * @param string $repo  Target repo name.
	 * @return string The new blob SHA in the target repo.
	 */
	private function copy_blob_to_repo( string $sha, string $owner, string $repo ): string {
		$blob = $this->api_get(
			self::API_BASE . '/repos/' . self::GLOBAL_OWNER . '/' . self::GLOBAL_REPO
				. '/git/blobs/' . $sha
		);

		$new_blob = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/blobs',
			[
				'content'  => $blob['content'],
				'encoding' => $blob['encoding'],
			]
		);

		return $new_blob['sha'];
	}

	/**
	 * Copy the devcontainer.json blob, injecting hostRequirements based on site size.
	 *
	 * @param string     $sha   Blob SHA in the global template repo.
	 * @param string     $owner Target repo owner.
	 * @param string     $repo  Target repo name.
	 * @param array|null $tier  Codespace tier from get_codespace_tier().
	 * @return string The new blob SHA in the target repo.
	 */
	private function copy_blob_with_host_requirements( string $sha, string $owner, string $repo, ?array $tier ): string {
		$blob = $this->api_get(
			self::API_BASE . '/repos/' . self::GLOBAL_OWNER . '/' . self::GLOBAL_REPO
				. '/git/blobs/' . $sha
		);

		$content = base64_decode( $blob['content'] ?? '' );
		if ( empty( $content ) ) {
			// Fallback: copy as-is if we can't decode.
			return $this->copy_blob_to_repo( $sha, $owner, $repo );
		}

		// Strip JS-style single-line comments before parsing.
		$stripped = preg_replace( '#^\s*//.*$#m', '', $content );
		$json     = json_decode( $stripped, true );

		if ( ! is_array( $json ) ) {
			// Fallback: copy as-is if JSON is invalid.
			return $this->copy_blob_to_repo( $sha, $owner, $repo );
		}

		// Use the provided tier, or max tier as fallback for >128 GB sites.
		$host_tier = $tier ?? [ 'cpus' => 16 ];

		// Patch only the cpus field — preserve any other hostRequirements
		// keys (memory, storage, etc.) that the template may declare.
		if ( ! isset( $json['hostRequirements'] ) || ! is_array( $json['hostRequirements'] ) ) {
			$json['hostRequirements'] = [];
		}
		$json['hostRequirements']['cpus'] = $host_tier['cpus'];

		$new_content = wp_json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$new_blob = $this->api_post(
			self::API_BASE . '/repos/' . $owner . '/' . $repo . '/git/blobs',
			[
				'content'  => base64_encode( $new_content ),
				'encoding' => 'base64',
			]
		);

		return $new_blob['sha'];
	}

	/**
	 * Calculate the total disk size of the site, excluding the uploads directory.
	 *
	 * @return int Size in bytes, or 0 if the calculation fails.
	 */
	private function calculate_site_disk_size(): int {
		try {
			$root       = rtrim( ABSPATH, '/' );
			$upload_dir = wp_upload_dir( null, false );
			$uploads    = isset( $upload_dir['basedir'] ) ? rtrim( $upload_dir['basedir'], '/' ) : '';

			$total = 0;

			$iterator = new \RecursiveDirectoryIterator(
				$root,
				\RecursiveDirectoryIterator::SKIP_DOTS
			);

			$files = new \RecursiveIteratorIterator(
				$iterator,
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $files as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}

				// Exclude the uploads directory.
				if ( ! empty( $uploads ) && str_starts_with( $file->getPathname(), $uploads ) ) {
					continue;
				}

				$total += $file->getSize();
			}

			return $total;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Map a disk size in bytes to a GitHub Codespace tier.
	 *
	 * Includes 20% headroom so the Codespace has breathing room for
	 * runtime artifacts, package caches, and temporary files.
	 *
	 * Tiers: 4-core = 32 GB, 8-core = 64 GB, 16-core = 128 GB.
	 * Only cpus is set in hostRequirements — disk walks hand-in-hand.
	 *
	 * @param int $disk_bytes Site disk size in bytes.
	 * @return array{cpus: int}|null Tier info, or null if the site exceeds 128 GB.
	 */
	private function get_codespace_tier( int $disk_bytes ): ?array {
		$gb            = 1024 * 1024 * 1024;
		$with_headroom = (int) ceil( $disk_bytes * 1.2 ); // 20% headroom.

		if ( $with_headroom <= 32 * $gb ) {
			return [ 'cpus' => 4 ];
		}
		if ( $with_headroom <= 64 * $gb ) {
			return [ 'cpus' => 8 ];
		}
		if ( $with_headroom <= 128 * $gb ) {
			return [ 'cpus' => 16 ];
		}

		return null;
	}

	/**
	 * Extract the hostRequirements.cpus value from a GitHub Contents API response.
	 *
	 * @param array $response Contents API response for devcontainer.json.
	 * @return int|null CPU count, or null if not set.
	 */
	private function extract_cpus_from_contents( array $response ): ?int {
		$content = base64_decode( $response['content'] ?? '' );
		if ( empty( $content ) ) {
			return null;
		}

		$stripped = preg_replace( '#^\s*//.*$#m', '', $content );
		$json     = json_decode( $stripped, true );

		return isset( $json['hostRequirements']['cpus'] ) ? (int) $json['hostRequirements']['cpus'] : null;
	}

	/**
	 * Map a CPU count to the corresponding disk size in GB.
	 *
	 * 4-core = 32 GB, 8-core = 64 GB, 16-core = 128 GB.
	 *
	 * @param int $cpus CPU count.
	 * @return int Disk size in GB.
	 */
	private function cpus_to_disk_gb( int $cpus ): int {
		return match ( $cpus ) {
			4  => 32,
			8  => 64,
			16 => 128,
			default => $cpus * 8,
		};
	}

	/**
	 * Push the BM_BOOTSTRAP_KEY value to the configured repo as an encrypted
	 * repo-level secret.
	 *
	 * The value is encrypted with libsodium sealed box against the repo's
	 * public key (as required by the GitHub secrets API). Supports both the
	 * Codespaces and Actions secret stores — defaults to Codespaces since
	 * that's what the migration pipeline consumes.
	 *
	 * @param string $type        Either "codespaces" or "actions".
	 * @param string $secret_name Name of the secret to create/update.
	 * @return array{
	 *     owner: string,
	 *     repo: string,
	 *     secret_name: string,
	 *     type: string,
	 *     created: bool,
	 *     secret_url: string
	 * }
	 * @throws \RuntimeException
	 */
	public function push_bootstrap_secret( string $type = 'codespaces', string $secret_name = 'BM_BOOTSTRAP_KEY' ): array {
		$type = strtolower( $type );
		if ( ! in_array( $type, [ 'codespaces', 'actions' ], true ) ) {
			throw new \RuntimeException( 'Secret type must be "codespaces" or "actions".' );
		}

		$secret_name = trim( $secret_name );
		if ( $secret_name === '' || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $secret_name ) ) {
			throw new \RuntimeException( 'Secret name must be a valid environment variable identifier (letters, digits, underscores; cannot start with a digit).' );
		}

		if ( ! function_exists( 'sodium_crypto_box_seal' ) ) {
			throw new \RuntimeException( 'PHP sodium extension is required to encrypt GitHub secrets but is not available on this server.' );
		}

		$bootstrap_key = Mighty_Backup_Api_Endpoint::get_bootstrap_key();
		if ( $bootstrap_key === '' ) {
			throw new \RuntimeException( 'Generate an API key first — the bootstrap key is empty.' );
		}

		// Validates presence of owner/repo/PAT and throws if missing.
		$config = $this->get_github_config();

		$base = sprintf(
			'%s/repos/%s/%s/%s/secrets',
			self::API_BASE,
			rawurlencode( $config['owner'] ),
			rawurlencode( $config['repo'] ),
			$type
		);

		// 1. Fetch the repo's public key for this secret store.
		$pk_response = $this->api_get( $base . '/public-key' );

		if ( empty( $pk_response['key'] ) || empty( $pk_response['key_id'] ) ) {
			throw new \RuntimeException( 'GitHub did not return a usable public key for this repo.' );
		}

		$public_key = base64_decode( $pk_response['key'], true );
		if ( $public_key === false || strlen( $public_key ) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES ) {
			throw new \RuntimeException( 'GitHub returned a malformed public key.' );
		}

		// 2. Encrypt the bootstrap key with sealed box.
		try {
			$ciphertext = sodium_crypto_box_seal( $bootstrap_key, $public_key );
		} catch ( \Throwable $e ) {
			throw new \RuntimeException( 'Failed to encrypt the secret: ' . $e->getMessage() );
		}

		$encrypted_value = base64_encode( $ciphertext );

		// 3. PUT the encrypted secret to the repo.
		$put_url = $base . '/' . rawurlencode( $secret_name );
		$result  = $this->api_put( $put_url, [
			'encrypted_value' => $encrypted_value,
			'key_id'          => $pk_response['key_id'],
		] );

		return [
			'owner'       => $config['owner'],
			'repo'        => $config['repo'],
			'secret_name' => $secret_name,
			'type'        => $type,
			'created'     => (int) $result['status'] === 201,
			'secret_url'  => sprintf(
				'https://github.com/%s/%s/settings/secrets/%s',
				rawurlencode( $config['owner'] ),
				rawurlencode( $config['repo'] ),
				$type
			),
		];
	}

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
				'User-Agent' => 'Mighty-Backup/' . MIGHTY_BACKUP_VERSION,
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
				'User-Agent'    => 'Mighty-Backup/' . MIGHTY_BACKUP_VERSION,
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
				'User-Agent'    => 'Mighty-Backup/' . MIGHTY_BACKUP_VERSION,
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		return $this->handle_response( $response, $url );
	}

	/**
	 * Make an authenticated PUT request to the GitHub API.
	 *
	 * Unlike the other helpers, this preserves the HTTP status code so callers
	 * can distinguish 201 (created) from 204 (updated) — both are success codes
	 * for the secrets API but carry different meaning.
	 *
	 * @return array{body: array, status: int}
	 */
	private function api_put( string $url, array $body ): array {
		$config = $this->get_github_config();

		$response = wp_remote_request( $url, [
			'method'  => 'PUT',
			'headers' => [
				'Accept'        => 'application/vnd.github+json',
				'Authorization' => 'Bearer ' . $config['pat'],
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'Mighty-Backup/' . MIGHTY_BACKUP_VERSION,
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 30,
		] );

		$decoded = $this->handle_response( $response, $url );

		return [
			'body'   => $decoded,
			'status' => (int) wp_remote_retrieve_response_code( $response ),
		];
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
