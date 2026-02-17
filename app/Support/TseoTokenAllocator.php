<?php

namespace App\Support;

/**
 * Asigna tokens SIEMPRE por tipo:
 * - TITLES: {{SECTION_N_TITLE}}
 * - PARAGRAPHS: {{SECTION_N_P}}
 * - BTN_TEXT/URL: usa los fijos primero (HERO/PACK), luego BTN_{n}_...
 *
 * Si quieres más fijos (ej. NAV/FOOTER), los agregas aquí.
 */
class TseoTokenAllocator
{
    private int $secTitleN = 1;
    private int $secPN = 1;

    private int $btnTextN = 1;
    private int $btnUrlN  = 1;

    private array $fixedBtnText = ['{{HERO_BTN_TEXT}}','{{PACK_BTN_TEXT}}'];
    private array $fixedBtnUrl  = ['{{HERO_BTN_URL}}','{{PACK_BTN_URL}}'];

    public function nextTitleToken(): ?string
    {
        $t = '{{SECTION_' . $this->secTitleN . '_TITLE}}';
        $this->secTitleN++;
        return $t;
    }

    public function nextParagraphToken(): ?string
    {
        $t = '{{SECTION_' . $this->secPN . '_P}}';
        $this->secPN++;
        return $t;
    }

    public function nextButtonTextToken(): ?string
    {
        if (!empty($this->fixedBtnText)) return array_shift($this->fixedBtnText);

        $t = '{{BTN_' . $this->btnTextN . '_TEXT}}';
        $this->btnTextN++;
        return $t;
    }

    public function nextButtonUrlToken(): ?string
    {
        if (!empty($this->fixedBtnUrl)) return array_shift($this->fixedBtnUrl);

        $t = '{{BTN_' . $this->btnUrlN . '_URL}}';
        $this->btnUrlN++;
        return $t;
    }
}
