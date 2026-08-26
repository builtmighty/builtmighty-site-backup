<?php
/**
 * Tests for Mighty_Backup_Retention_Manager — pruning logic.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class RetentionManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Pruning writes to the live log. Log_Stream buffers in a static and
        // flushes to options every 10 entries, so whether a flush lands inside
        // any given test depends on what ran before it — stub the option calls
        // rather than depend on that ordering.
        Functions\when( 'get_site_option' )->justReturn( [] );
        Functions\when( 'update_site_option' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Build a newest-first listing, as list_objects() returns.
     *
     * Days are zero-padded so the fixture's lexicographic order matches its
     * chronological order — pruning sorts by key, so an unpadded fixture would
     * silently disagree with the code under test.
     *
     * @param int    $count  How many objects.
     * @param string $stem   Object-name stem ('backup', 'acme-store', …).
     * @param string $prefix Key prefix, i.e. "<client_path>/<type>/".
     */
    private function make_objects( int $count, string $stem = 'backup', string $prefix = 'client/databases/' ): array {
        $objects = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $day       = str_pad( (string) $i, 2, '0', STR_PAD_LEFT );
            $objects[] = [
                'Key'          => "{$prefix}{$stem}-2026-01-{$day}-030000.sql.gz",
                'Size'         => 1024 * 1024,
                'LastModified' => "2026-01-{$day} 03:00:00",
            ];
        }
        // Return newest-first (as list_objects() does).
        return array_reverse( $objects );
    }

    public function test_prune_does_nothing_when_under_limit(): void {
        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $this->make_objects( 3 ) );
        $client->expects( $this->never() )->method( 'delete_objects' );

        $manager = new Mighty_Backup_Retention_Manager( $client, 7 );
        $result  = $manager->prune();

        $this->assertSame( 0, $result['databases_deleted'] );
        $this->assertSame( 0, $result['files_deleted'] );
    }

    public function test_prune_does_nothing_when_at_limit(): void {
        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $this->make_objects( 7 ) );
        $client->expects( $this->never() )->method( 'delete_objects' );

        $manager = new Mighty_Backup_Retention_Manager( $client, 7 );
        $result  = $manager->prune();

        $this->assertSame( 0, $result['databases_deleted'] );
        $this->assertSame( 0, $result['files_deleted'] );
    }

    public function test_prune_deletes_excess_backups(): void {
        $objects = $this->make_objects( 10 );

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $objects );

        // Expect 3 to be deleted (10 - 7 = 3), applied to both prefixes.
        $client->expects( $this->exactly( 2 ) )
               ->method( 'delete_objects' )
               ->with( $this->countOf( 3 ) );

        $manager = new Mighty_Backup_Retention_Manager( $client, 7 );
        $result  = $manager->prune();

        $this->assertSame( 3, $result['databases_deleted'] );
        $this->assertSame( 3, $result['files_deleted'] );
    }

    public function test_prune_keeps_newest_backups(): void {
        $objects      = $this->make_objects( 5 );
        $deleted_keys = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $objects );
        $client->method( 'delete_objects' )->willReturnCallback( function ( array $keys ) use ( &$deleted_keys ) {
            $deleted_keys = array_merge( $deleted_keys, $keys );
        } );

        $manager = new Mighty_Backup_Retention_Manager( $client, 3 );
        $manager->prune();

        // With 5 objects and limit 3, the 2 oldest go — and it matters *which*
        // two, not just how many: pruning relies on the timestamp leading the
        // sort, so assert the exact keys.
        $expected = [
            'client/databases/backup-2026-01-02-030000.sql.gz',
            'client/databases/backup-2026-01-01-030000.sql.gz',
        ];

        $this->assertCount( 4, $deleted_keys ); // 2 prefixes × 2 deletions each.
        $this->assertSame( array_merge( $expected, $expected ), $deleted_keys );

        // The three newest must survive.
        foreach ( [ '01-05', '01-04', '01-03' ] as $kept ) {
            $this->assertNotContains(
                "client/databases/backup-2026-{$kept}-030000.sql.gz",
                $deleted_keys
            );
        }
    }

    public function test_retention_count_minimum_is_one(): void {
        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $this->make_objects( 3 ) );

        // Passing 0 should be clamped to 1.
        $manager = new Mighty_Backup_Retention_Manager( $client, 0 );

        $client->expects( $this->exactly( 2 ) )
               ->method( 'delete_objects' )
               ->with( $this->countOf( 2 ) ); // 3 objects, keep 1, delete 2.

        $manager->prune();
    }

    public function test_default_stem_scopes_listing_to_legacy_layout(): void {
        $prefixes = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturnCallback(
            function ( string $prefix ) use ( &$prefixes ) {
                $prefixes[] = $prefix;
                return [];
            }
        );

        ( new Mighty_Backup_Retention_Manager( $client, 7 ) )->prune();

        // Every object written before multisource existed is named "backup-*",
        // so the default stem must still match all of them.
        $this->assertSame( [ 'databases/backup-', 'files/backup-' ], $prefixes );
    }

    public function test_multisource_stem_scopes_listing_to_this_site(): void {
        $prefixes = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturnCallback(
            function ( string $prefix ) use ( &$prefixes ) {
                $prefixes[] = $prefix;
                return [];
            }
        );

        ( new Mighty_Backup_Retention_Manager( $client, 7, 'acme-store' ) )->prune();

        $this->assertSame( [ 'databases/acme-store-', 'files/acme-store-' ], $prefixes );
    }

    public function test_blank_stem_falls_back_to_default(): void {
        $prefixes = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturnCallback(
            function ( string $prefix ) use ( &$prefixes ) {
                $prefixes[] = $prefix;
                return [];
            }
        );

        ( new Mighty_Backup_Retention_Manager( $client, 7, '' ) )->prune();

        $this->assertSame( [ 'databases/backup-', 'files/backup-' ], $prefixes );
    }

    /**
     * The load-bearing multisource guarantee: because the prefix pins the stem,
     * a sibling site's objects and this site's own pre-multisource "backup-*"
     * objects are never even listed, so they can never be deleted.
     */
    public function test_multisource_prune_never_touches_siblings_or_legacy_objects(): void {
        $bucket = array_merge(
            $this->make_objects( 5, 'acme-store', 'shared-repo/databases/' ),
            $this->make_objects( 5, 'zulu-store', 'shared-repo/databases/' ),
            $this->make_objects( 5, 'backup', 'shared-repo/databases/' )
        );

        $deleted_keys = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        // Emulate the S3 server-side Prefix filter that list_objects() delegates to.
        $client->method( 'list_objects' )->willReturnCallback(
            function ( string $prefix ) use ( $bucket ) {
                return array_values( array_filter(
                    $bucket,
                    static fn( $object ) => str_contains( $object['Key'], $prefix )
                ) );
            }
        );
        $client->method( 'delete_objects' )->willReturnCallback( function ( array $keys ) use ( &$deleted_keys ) {
            $deleted_keys = array_merge( $deleted_keys, $keys );
        } );

        $manager = new Mighty_Backup_Retention_Manager( $client, 2, 'acme-store' );
        $result  = $manager->prune();

        // 5 objects for this site, keep 2 => 3 deleted (databases only; the
        // files listing is empty in this fixture).
        $this->assertSame( 3, $result['databases_deleted'] );
        $this->assertSame( 0, $result['files_deleted'] );

        foreach ( $deleted_keys as $key ) {
            $this->assertStringContainsString( 'acme-store-', $key, "Deleted a key outside this site: {$key}" );
        }

        // Explicitly: no sibling and no legacy object was reaped.
        $sibling_and_legacy = array_filter(
            $deleted_keys,
            static fn( $key ) => str_contains( $key, 'zulu-store-' ) || str_contains( $key, '/backup-' )
        );
        $this->assertSame( [], $sibling_and_legacy );
    }

    /**
     * Demonstrates why the stem has to be in the prefix. An unscoped listing of
     * a shared prefix sorts by site name before timestamp, so slicing off the
     * tail would wipe whole sibling histories.
     */
    public function test_unscoped_shared_prefix_would_sort_by_site_before_timestamp(): void {
        $shared = array_merge(
            $this->make_objects( 3, 'acme-store', 'shared-repo/databases/' ),
            $this->make_objects( 3, 'zulu-store', 'shared-repo/databases/' )
        );

        $keys = array_column( $shared, 'Key' );
        usort( $keys, static fn( $a, $b ) => strcmp( $b, $a ) );

        // Descending key order puts every zulu-store object ahead of every
        // acme-store object — timestamps do not lead the comparison.
        $this->assertStringContainsString( 'zulu-store-', $keys[0] );
        $this->assertStringContainsString( 'zulu-store-', $keys[2] );
        $this->assertStringContainsString( 'acme-store-', $keys[3] );

        // So keeping the newest 3 would keep zulu-store's history and queue all
        // of acme-store's — including its newest backup — for deletion.
        $tail = array_slice( $keys, 3 );
        foreach ( $tail as $key ) {
            $this->assertStringContainsString( 'acme-store-', $key );
        }
    }
}
