<?php

declare(strict_types=1);

namespace Drupal\Tests\access_display\Unit;

use Drupal\access_display\Service\GuestTokenManager;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;

/**
 * @coversDefaultClass \Drupal\access_display\Service\GuestTokenManager
 * @group access_display
 */
class GuestTokenManagerTest extends UnitTestCase {

  public function testGetTokenReturnsNullWhenFieldEmpty(): void {
    $list = new class { public $value = ''; };
    $list->value = '';
    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->with('field_guest_token')->willReturn(TRUE);
    $user->method('get')->with('field_guest_token')->willReturn($list);

    $tm = new GuestTokenManager($this->createMock(EntityTypeManagerInterface::class));
    $this->assertNull($tm->getToken($user));
  }

  public function testGetTokenReturnsNullWhenFieldMissing(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->with('field_guest_token')->willReturn(FALSE);

    $tm = new GuestTokenManager($this->createMock(EntityTypeManagerInterface::class));
    $this->assertNull($tm->getToken($user));
  }

  public function testGetTokenReturnsValue(): void {
    $list = new class { public $value = ''; };
    $list->value = 'abc123def456';
    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->with('field_guest_token')->willReturn(TRUE);
    $user->method('get')->with('field_guest_token')->willReturn($list);

    $tm = new GuestTokenManager($this->createMock(EntityTypeManagerInterface::class));
    $this->assertSame('abc123def456', $tm->getToken($user));
  }

  public function testMintTokenIdempotentByDefault(): void {
    $list = new class { public $value = ''; };
    $list->value = 'existing-token-value';
    $user = $this->createMock(UserInterface::class);
    $user->method('hasField')->with('field_guest_token')->willReturn(TRUE);
    $user->method('get')->with('field_guest_token')->willReturn($list);
    // save() must not be called when token already exists and force=FALSE.
    $user->expects($this->never())->method('save');

    $tm = new GuestTokenManager($this->createMock(EntityTypeManagerInterface::class));
    $this->assertSame('existing-token-value', $tm->mintToken($user));
  }

  public function testLoadGuestByTokenReturnsNullForBlankOrTooLong(): void {
    $tm = new GuestTokenManager($this->createMock(EntityTypeManagerInterface::class));
    $this->assertNull($tm->loadGuestByToken(''));
    $this->assertNull($tm->loadGuestByToken(str_repeat('a', 65)));
  }

  public function testLoadGuestByTokenReturnsNullOnMiss(): void {
    $query = $this->createMock(QueryInterface::class);
    $query->method('accessCheck')->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn([]);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->with('user')->willReturn($storage);

    $tm = new GuestTokenManager($etm);
    $this->assertNull($tm->loadGuestByToken('some-token-value'));
  }

}
