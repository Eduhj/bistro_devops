<?php
declare(strict_types=1);
// Funções compartilhadas entre as páginas.
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function brl($v): string { return 'R$ '.number_format((float)$v, 2, ',', '.'); }
// Formata uma duração em minutos: "8 min", "1 h 05 min".
function duracao($min): string {
    $min = max(0, (int)$min);
    return $min < 60 ? $min.' min' : intdiv($min, 60).' h '.str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT).' min';
}
// Minutos em preparo a partir dos quais a comanda é destacada como atrasada.
const MINUTOS_ATRASO = 30;
