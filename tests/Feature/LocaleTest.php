<?php

namespace Tests\Feature;

use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_locale_switching_route_updates_session(): void
    {
        $locales = ['it', 'fr_FR', 'es_ES', 'pt_PT'];
        foreach ($locales as $locale) {
            $response = $this->get(route('lang.switch', $locale));
            $response->assertSessionHas('locale', $locale);
        }
    }

    public function test_invalid_locale_switch_ignored(): void
    {
        $response = $this->get(route('lang.switch', 'non_existing_locale'));
        $response->assertSessionMissing('locale');
    }
}
