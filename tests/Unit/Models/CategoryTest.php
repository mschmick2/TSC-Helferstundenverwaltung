<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Category;
use PHPUnit\Framework\TestCase;

/**
 * Unit-Tests für das Category-Model
 */
class CategoryTest extends TestCase
{
    /** @test */
    public function from_array_setzt_alle_felder(): void
    {
        $cat = Category::fromArray([
            'id' => '5',
            'name' => 'Veranstaltungen',
            'description' => 'Hilfe bei Vereinsveranstaltungen',
            'sort_order' => '10',
            'is_active' => '1',
            'deleted_at' => null,
        ]);

        $this->assertSame(5, $cat->getId());
        $this->assertSame('Veranstaltungen', $cat->getName());
        $this->assertSame('Hilfe bei Vereinsveranstaltungen', $cat->getDescription());
        $this->assertSame(10, $cat->getSortOrder());
        $this->assertTrue($cat->isActive());
        $this->assertNull($cat->getDeletedAt());
    }

    /** @test */
    public function from_array_defaults(): void
    {
        $cat = Category::fromArray([]);

        $this->assertNull($cat->getId());
        $this->assertSame('', $cat->getName());
        $this->assertNull($cat->getDescription());
        $this->assertSame(0, $cat->getSortOrder());
        $this->assertTrue($cat->isActive());
    }

    /** @test */
    public function deaktivierte_kategorie(): void
    {
        $cat = Category::fromArray(['is_active' => '0']);

        $this->assertFalse($cat->isActive());
    }

    /** @test */
    public function soft_deleted_kategorie(): void
    {
        $cat = Category::fromArray(['deleted_at' => '2025-02-09 10:00:00']);

        $this->assertSame('2025-02-09 10:00:00', $cat->getDeletedAt());
    }
}
