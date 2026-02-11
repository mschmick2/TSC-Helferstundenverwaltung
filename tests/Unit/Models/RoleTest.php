<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Role;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für das Role-Model
 */
class RoleTest extends TestCase
{
    /** @test */
    public function from_array_setzt_felder(): void
    {
        $role = Role::fromArray([
            'id' => '3',
            'name' => 'pruefer',
            'description' => 'Kann Anträge prüfen',
        ]);

        $this->assertSame(3, $role->getId());
        $this->assertSame('pruefer', $role->getName());
        $this->assertSame('Kann Anträge prüfen', $role->getDescription());
    }

    /** @test */
    public function from_array_defaults(): void
    {
        $role = Role::fromArray([]);

        $this->assertNull($role->getId());
        $this->assertSame('', $role->getName());
        $this->assertNull($role->getDescription());
    }

    /** @test */
    public function konstanten_definiert(): void
    {
        $this->assertSame('mitglied', Role::MITGLIED);
        $this->assertSame('erfasser', Role::ERFASSER);
        $this->assertSame('pruefer', Role::PRUEFER);
        $this->assertSame('auditor', Role::AUDITOR);
        $this->assertSame('administrator', Role::ADMINISTRATOR);
    }

    /**
     * @test
     * @dataProvider display_name_provider
     */
    public function get_display_name_fuer_alle_rollen(string $roleName, string $expected): void
    {
        $role = Role::fromArray(['name' => $roleName]);

        $this->assertSame($expected, $role->getDisplayName());
    }

    public static function display_name_provider(): array
    {
        return [
            'mitglied' => ['mitglied', 'Mitglied'],
            'erfasser' => ['erfasser', 'Erfasser'],
            'pruefer' => ['pruefer', 'Prüfer'],
            'auditor' => ['auditor', 'Auditor'],
            'administrator' => ['administrator', 'Administrator'],
        ];
    }

    /** @test */
    public function get_display_name_unbekannte_rolle(): void
    {
        $role = Role::fromArray(['name' => 'superuser']);

        $this->assertSame('superuser', $role->getDisplayName());
    }
}
