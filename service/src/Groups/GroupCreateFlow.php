<?php

declare(strict_types=1);

namespace Claudia\Groups;

use Claudia\Commands\ChatFlow;
use Claudia\Net\Out;
use Claudia\Players\Session;
use Claudia\UserError;
use Claudia\Util\Text;

/**
 * Formulario por chat para fundar un grupo: nombre, tag, descripción, privacidad y confirmación.
 * Recién al confirmar se cobra el precio.
 */
final class GroupCreateFlow implements ChatFlow
{
    private string $step = 'name';
    private string $name = '';
    private string $tag = '';
    private string $description = '';
    private bool $private = false;

    /** @param callable(array<string,mixed>, int):void $onCreated */
    public function __construct(
        private readonly GroupService $groups,
        private readonly Out $out,
        private $onCreated,
    ) {
    }

    public function begin(Session $s): void
    {
        $this->say($s, 'Vamos a fundar tu grupo ({green}' . Text::coins($this->groups->price()) . '{default} URU Coins, se cobra al final). Escribí "cancelar" para salir.');
        $this->ask($s);
    }

    public function answer(Session $s, string $text): bool
    {
        switch ($this->step) {
            case 'name':
                $this->name = $this->groups->validateName($text);
                $this->step = 'tag';
                break;
            case 'tag':
                $this->tag = $this->groups->validateTag($text);
                $this->step = 'description';
                break;
            case 'description':
                $this->description = $this->groups->validateDescription($text);
                $this->step = 'privacy';
                break;
            case 'privacy':
                $v = Text::fold($text);
                if (str_starts_with($v, 'pub')) {
                    $this->private = false;
                } elseif (str_starts_with($v, 'priv')) {
                    $this->private = true;
                } else {
                    throw new UserError('Contestá "publico" o "privado".');
                }
                $this->step = 'confirm';
                break;
            case 'confirm':
                $v = Text::fold($text);
                if (in_array($v, ['no', 'n'], true)) {
                    $this->say($s, 'Listo, no se creó nada.');
                    return true;
                }
                if (!in_array($v, ['si', 's', 'dale', 'confirmar'], true)) {
                    throw new UserError('Contestá "si" para crear el grupo o "no" para cancelar.');
                }
                $group = $this->groups->create((int) $s->userId, $this->name, $this->tag, $this->description, $this->private);
                ($this->onCreated)($group, (int) $s->userId);
                return true;
        }
        $this->ask($s);
        return false;
    }

    public function cancelled(Session $s, bool $timeout): void
    {
        $this->say($s, $timeout ? 'Se venció el tiempo del formulario del grupo. Empezá de nuevo con /creargrupo.' : 'Cancelaste la creación del grupo.');
    }

    private function ask(Session $s): void
    {
        $g = $this->groups;
        $msg = match ($this->step) {
            'name' => 'Paso 1/5: escribí en el chat el {green}nombre{default} del grupo.',
            'tag' => "Paso 2/5: escribí el {green}tag{default} (hasta 6 caracteres, va a salir así en el chat: [TAG]{$s->nick}).",
            'description' => 'Paso 3/5: escribí una {green}descripción{default} corta.',
            'privacy' => 'Paso 4/5: ¿{green}publico{default} (entra cualquiera) o {green}privado{default} (vos aceptás las solicitudes)?',
            'confirm' => "Paso 5/5: {green}{$this->name}{default} [{$this->tag}] - {$this->description} - " . ($this->private ? 'privado' : 'público')
                . '. Cuesta ' . Text::coins($g->price()) . '. ¿Lo creo? (si/no)',
            default => '',
        };
        $this->say($s, $msg);
    }

    private function say(Session $s, string $text): void
    {
        $this->out->chat($s->slot, $text);
    }
}
