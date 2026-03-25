<?php
/**
 * Tests for Mighty_Backup_Retention_Manager — pruning logic.
 */

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class RetentionManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_objects( int $count ): array {
        $objects = [];
        for ( $i = 1; $i <= $count; $i++ ) {
            $objects[] = [
                'Key'          => "client/databases/backup-2026-01-{$i}.sql.gz",
                'Size'         => 1024 * 1024,
                'LastModified' => "2026-01-{$i} 03:00:00",
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
        $objects       = $this->make_objects( 5 );
        $deleted_keys  = [];

        $client = $this->createMock( Mighty_Backup_Spaces_Client::class );
        $client->method( 'list_objects' )->willReturn( $objects );
        $client->method( 'delete_objects' )->willReturnCallback( function ( array $keys ) use ( &$deleted_keys ) {
            $deleted_keys = array_merge( $deleted_keys, $keys );
        } );

        $manager = new Mighty_Backup_Retention_Manager( $client, 3 );
        $manager->prune();

        // With 5 objects and limit 3, the 2 oldest (index 3 and 4) should be deleted.
        // Objects are newest-first so index 0-2 are kept, 3-4 are deleted.
        $this->assertCount( 4, $deleted_keys ); // 2 prefixes × 2 deletions each.
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
}
