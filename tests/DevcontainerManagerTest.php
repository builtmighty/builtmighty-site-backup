<?php
/**
 * Tests for Mighty_Devcontainer_Manager's pure helpers.
 *
 * These two functions decide what a `.devcontainer` upgrade PR contains. A bug
 * in the tree builder deletes a repo's files without replacing them, and a bug
 * in the cpus patcher ships a Codespace sized for the wrong machine — neither
 * failure is visible until someone opens the PR. Both are static and pure, so
 * they are exercised here without touching the GitHub API.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class DevcontainerManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'wp_json_encode' )->alias( static function ( $data, $flags = 0 ) {
            return json_encode( $data, $flags );
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     * Fixtures
     * ------------------------------------------------------------------ */

    /** The real template devcontainer.json, comments and tabs intact. */
    private function template_json(): string {
        return (string) file_get_contents( __DIR__ . '/fixtures/devcontainer-template.json' );
    }

    private function blob( string $path, string $sha = 'sha-' , string $mode = '100644' ): array {
        return [ 'path' => $path, 'mode' => $mode, 'type' => 'blob', 'sha' => $sha . $path ];
    }

    /** A template tree: three files, one of them executable. */
    private function template_tree(): array {
        return [
            [ 'path' => '.devcontainer', 'mode' => '040000', 'type' => 'tree', 'sha' => 't1' ],
            $this->blob( '.devcontainer/devcontainer.json' ),
            $this->blob( '.devcontainer/Dockerfile' ),
            $this->blob( '.devcontainer/bin/bm-up', 'sha-', '100755' ),
        ];
    }

    /** Blob SHAs as the copy loop would produce them. */
    private function blob_shas(): array {
        return [
            '.devcontainer/devcontainer.json' => 'new1',
            '.devcontainer/Dockerfile'        => 'new2',
            '.devcontainer/bin/bm-up'         => 'new3',
        ];
    }

    private function find_item( array $items, string $path ): ?array {
        foreach ( $items as $item ) {
            if ( $item['path'] === $path ) {
                return $item;
            }
        }
        return null;
    }

    private function paths_of( array $items ): array {
        return array_column( $items, 'path' );
    }

    /* ---------------------------------------------------------------------
     * build_devcontainer_tree_items()
     * ------------------------------------------------------------------ */

    public function test_every_template_file_is_added_with_its_own_mode(): void {
        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            [],
            $this->template_tree(),
            $this->blob_shas()
        );

        $this->assertSame(
            [ '.devcontainer/devcontainer.json', '.devcontainer/Dockerfile', '.devcontainer/bin/bm-up' ],
            $this->paths_of( $items )
        );

        // Mode travels with the file, so the executable bit on bin/* survives.
        $this->assertSame( '100755', $this->find_item( $items, '.devcontainer/bin/bm-up' )['mode'] );
        $this->assertSame( '100644', $this->find_item( $items, '.devcontainer/Dockerfile' )['mode'] );

        // SHAs come from the copy loop, not the template.
        $this->assertSame( 'new3', $this->find_item( $items, '.devcontainer/bin/bm-up' )['sha'] );
    }

    public function test_repo_only_files_are_deleted_including_setup(): void {
        $repo_tree = [
            $this->blob( '.devcontainer/devcontainer.json', 'old-' ),
            $this->blob( '.devcontainer/old-helper.sh', 'old-' ),
            $this->blob( '.devcontainer/setup/provision.sh', 'old-' ),
        ];

        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            $repo_tree,
            $this->template_tree(),
            $this->blob_shas()
        );

        // setup/ is no longer exempt — a wholesale replacement removes it.
        foreach ( [ '.devcontainer/old-helper.sh', '.devcontainer/setup/provision.sh' ] as $gone ) {
            $item = $this->find_item( $items, $gone );
            $this->assertNotNull( $item, "{$gone} should be in the tree" );
            $this->assertNull( $item['sha'], "{$gone} should be a delete (null sha)" );
        }
    }

    public function test_a_path_in_both_appears_once_as_an_add(): void {
        $repo_tree = [ $this->blob( '.devcontainer/devcontainer.json', 'old-' ) ];

        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            $repo_tree,
            $this->template_tree(),
            $this->blob_shas()
        );

        $matches = array_filter(
            $items,
            static fn( $i ) => $i['path'] === '.devcontainer/devcontainer.json'
        );

        // A same-path delete AND add in one tree array has no defined
        // resolution order, so there must only ever be one entry.
        $this->assertCount( 1, $matches, 'devcontainer.json must not be both added and deleted' );
        $this->assertSame( 'new1', array_values( $matches )[0]['sha'] );
    }

    public function test_files_outside_devcontainer_are_never_touched(): void {
        $repo_tree = [
            $this->blob( 'README.md', 'old-' ),
            $this->blob( '.github/workflows/deploy.yml', 'old-' ),
            $this->blob( 'wp-content/themes/acme/style.css', 'old-' ),
            // A sibling whose name merely starts the same way.
            $this->blob( '.devcontainer-backup/notes.md', 'old-' ),
        ];

        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            $repo_tree,
            $this->template_tree(),
            $this->blob_shas()
        );

        foreach ( $this->paths_of( $items ) as $path ) {
            $this->assertStringStartsWith( '.devcontainer/', $path );
        }
    }

    public function test_non_blob_entries_are_skipped(): void {
        $repo_tree = [
            [ 'path' => '.devcontainer/bin', 'mode' => '040000', 'type' => 'tree', 'sha' => 'r1' ],
            [ 'path' => '.devcontainer/vendored', 'mode' => '160000', 'type' => 'commit', 'sha' => 'r2' ],
        ];

        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            $repo_tree,
            $this->template_tree(),
            $this->blob_shas()
        );

        // Sub-trees are implied by their blobs' paths; emitting them (or a
        // submodule pointer) as deletes would be wrong.
        $this->assertNotContains( '.devcontainer/bin', $this->paths_of( $items ) );
        $this->assertNotContains( '.devcontainer/vendored', $this->paths_of( $items ) );
    }

    public function test_a_template_file_with_no_blob_is_left_alone_not_deleted(): void {
        $repo_tree = [ $this->blob( '.devcontainer/Dockerfile', 'old-' ) ];

        // Dockerfile's blob is missing from the map.
        $shas = $this->blob_shas();
        unset( $shas['.devcontainer/Dockerfile'] );

        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            $repo_tree,
            $this->template_tree(),
            $shas
        );

        // Emitting it with a missing SHA would be rejected by GitHub, and
        // emitting a delete would drop the file with nothing to replace it.
        $this->assertNull( $this->find_item( $items, '.devcontainer/Dockerfile' ) );
    }

    public function test_empty_repo_tree_produces_adds_only(): void {
        $items = Mighty_Devcontainer_Manager::build_devcontainer_tree_items(
            [],
            $this->template_tree(),
            $this->blob_shas()
        );

        foreach ( $items as $item ) {
            $this->assertNotNull( $item['sha'] );
        }
    }

    /* ---------------------------------------------------------------------
     * removed_devcontainer_paths()
     * ------------------------------------------------------------------ */

    public function test_removed_paths_lists_only_repo_only_files_sorted(): void {
        $repo_tree = [
            $this->blob( '.devcontainer/setup/provision.sh', 'old-' ),
            $this->blob( '.devcontainer/Dockerfile', 'old-' ),
            $this->blob( '.devcontainer/aaa-first.sh', 'old-' ),
            $this->blob( 'README.md', 'old-' ),
        ];

        $removed = Mighty_Devcontainer_Manager::removed_devcontainer_paths(
            $repo_tree,
            $this->template_tree()
        );

        $this->assertSame(
            [ '.devcontainer/aaa-first.sh', '.devcontainer/setup/provision.sh' ],
            $removed
        );
    }

    public function test_removed_paths_is_empty_when_repo_matches_template(): void {
        $repo_tree = [
            $this->blob( '.devcontainer/devcontainer.json', 'old-' ),
            $this->blob( '.devcontainer/Dockerfile', 'old-' ),
            $this->blob( '.devcontainer/bin/bm-up', 'old-' ),
        ];

        $this->assertSame(
            [],
            Mighty_Devcontainer_Manager::removed_devcontainer_paths( $repo_tree, $this->template_tree() )
        );
    }

    /* ---------------------------------------------------------------------
     * set_host_requirements_cpus()
     * ------------------------------------------------------------------ */

    public function test_patch_changes_only_the_cpus_value_in_the_real_template(): void {
        $source  = $this->template_json();
        $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 16 );

        $this->assertNotNull( $patched );

        // Byte-level: the ONLY difference is 4 -> 16 inside hostRequirements.
        $this->assertSame(
            str_replace( "\"cpus\": 4,", "\"cpus\": 16,", $source ),
            $patched
        );
    }

    public function test_patch_preserves_comments_and_formatting(): void {
        $source  = $this->template_json();
        $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 8 );

        // The template is roughly half commentary explaining why each setting
        // is what it is; a decode/re-encode would delete all of it.
        $this->assertSame(
            substr_count( $source, '//' ),
            substr_count( (string) $patched, '//' ),
            'every comment must survive'
        );
        $this->assertStringContainsString( 'NO FEATURES.', (string) $patched );
        $this->assertStringContainsString( "\t\"hostRequirements\": {", (string) $patched, 'tabs preserved' );
    }

    public function test_patch_leaves_memory_to_the_template(): void {
        $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $this->template_json(), 32 );

        $decoded = json_decode( preg_replace( '#^\s*//.*$#m', '', (string) $patched ), true );

        $this->assertSame( 32, $decoded['hostRequirements']['cpus'] );
        // memory is coupled to innodb_buffer_pool_size in the template's
        // db/*.cnf, so it must come from the template, never the repo.
        $this->assertSame( '8gb', $decoded['hostRequirements']['memory'] );
    }

    public function test_patched_output_always_reparses_to_the_requested_cpus(): void {
        foreach ( [ 4, 8, 16, 32 ] as $cpus ) {
            $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $this->template_json(), $cpus );
            $decoded = json_decode( preg_replace( '#^\s*//.*$#m', '', (string) $patched ), true );

            $this->assertSame( $cpus, $decoded['hostRequirements']['cpus'] );
        }
    }

    public function test_patch_inserts_cpus_when_the_block_has_none(): void {
        $source = "{\n\t\"name\": \"x\",\n\t\"hostRequirements\": {\n\t\t\"memory\": \"8gb\"\n\t}\n}\n";

        $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 8 );

        $this->assertNotNull( $patched );
        $decoded = json_decode( (string) $patched, true );
        $this->assertSame( 8, $decoded['hostRequirements']['cpus'] );
        $this->assertSame( '8gb', $decoded['hostRequirements']['memory'] );
    }

    public function test_patch_returns_null_when_there_is_no_host_requirements_block(): void {
        $source = "{\n\t\"name\": \"x\",\n\t\"image\": \"ghcr.io/acme/base:5\"\n}\n";

        // Null tells the caller to fall back rather than guess where to insert.
        $this->assertNull( Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 8 ) );
    }

    public function test_patch_returns_null_when_the_result_would_not_parse(): void {
        // Trailing comma after the block makes this invalid JSON, so the
        // verification step must reject the patch rather than commit it.
        $source = "{\n\t\"hostRequirements\": {\n\t\t\"cpus\": 4\n\t},\n}\n";

        $this->assertNull( Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 8 ) );
    }

    public function test_patch_ignores_a_cpus_key_outside_host_requirements(): void {
        $source = "{\n\t\"customizations\": {\n\t\t\"cpus\": 99\n\t},\n\t\"hostRequirements\": {\n\t\t\"cpus\": 4\n\t}\n}\n";

        $patched = Mighty_Devcontainer_Manager::set_host_requirements_cpus( $source, 8 );
        $decoded = json_decode( (string) $patched, true );

        $this->assertSame( 8, $decoded['hostRequirements']['cpus'] );
        $this->assertSame( 99, $decoded['customizations']['cpus'], 'unrelated cpus key must be untouched' );
    }

    /* ---------------------------------------------------------------------
     * rewrite_host_requirements_cpus() — the fallback
     * ------------------------------------------------------------------ */

    public function test_fallback_rewrite_sets_cpus_and_keeps_other_keys(): void {
        $rewritten = Mighty_Devcontainer_Manager::rewrite_host_requirements_cpus( $this->template_json(), 16 );

        $decoded = json_decode( (string) $rewritten, true );

        $this->assertSame( 16, $decoded['hostRequirements']['cpus'] );
        $this->assertSame( '8gb', $decoded['hostRequirements']['memory'] );
        $this->assertSame( '5.0.0', $decoded['version'] );
    }

    public function test_fallback_rewrite_returns_null_for_unparseable_source(): void {
        $this->assertNull(
            Mighty_Devcontainer_Manager::rewrite_host_requirements_cpus( "{ not json at all ", 8 )
        );
    }
}
