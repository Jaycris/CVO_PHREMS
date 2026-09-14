<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DirectoryScrollTest extends TestCase
{
    public function test_directory_tables_use_a_teleported_floating_horizontal_scrollbar(): void
    {
        $html = Blade::render('<x-directory-scroll><table><tr><td>Directory</td></tr></table></x-directory-scroll>');

        $this->assertStringContainsString('x-teleport="body"', $html);
        $this->assertStringContainsString('Floating horizontal table scroll', $html);
        $this->assertStringContainsString('fixed bottom-3', $html);
        $this->assertStringContainsString('tabindex="0"', $html);
        $this->assertStringNotContainsString('topScroller', $html);
    }
}
