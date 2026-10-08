<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Service;

use OCA\Audiolog\Tests\Unit\Support\TestHelpers;
use PHPUnit\Framework\TestCase;

/**
 * M5: tasks come only from the task table the prompt asks for (or from a
 * bullet list under a tasks heading), never from other tables of the
 * minutes, and bullet lines keep their full title.
 */
class ExtractTasksTest extends TestCase {
    private function extract(string $text): array {
        $svc = TestHelpers::audioService($this);
        $m = new \ReflectionMethod($svc, 'extractTasks');
        return $m->invoke($svc, $text);
    }

    public function testTaskTableFromPrompt(): void {
        $text = "## Ata\n\n| Participante | Cargo | Área |\n|---|---|---|\n| Maria | Gerente | Vendas |\n| João | Analista | TI |\n\n"
            . "TAREFAS:\n| Tarefa | Responsável | Prazo |\n|---|---|---|\n| Enviar relatório trimestral | Ana | 10/11 |\n| Revisar contrato | João | Não definido |\n\nFim.";
        $tasks = $this->extract($text);
        $this->assertSame([
            ['title' => 'Enviar relatório trimestral', 'assignee' => 'Ana', 'dueDate' => '10/11'],
            ['title' => 'Revisar contrato', 'assignee' => 'João', 'dueDate' => 'Não definido'],
        ], $tasks);
    }

    public function testParticipantsTableAloneYieldsNoTask(): void {
        $text = "| Nome | Cargo | Área |\n|---|---|---|\n| Maria | Gerente | Vendas |\n";
        $this->assertSame([], $this->extract($text));
    }

    public function testEnglishAndNumberedTableHeaders(): void {
        $text = "| # | Task | Owner | Due |\n|---|---|---|---|\n| 1 | Ship the release | Bob | Friday |\n";
        $this->assertSame([['title' => 'Ship the release', 'assignee' => 'Bob', 'dueDate' => 'Friday']], $this->extract($text));
    }

    public function testBulletListUnderTasksHeadingKeepsFullTitle(): void {
        $text = "## Resumo\n- Discutimos o orçamento\n\n## Tarefas\n- Enviar relatório - Responsável: Ana - Prazo: sexta\n* Agendar reunião com o cliente\n\n## Outros\n- Não é tarefa";
        $this->assertSame([
            ['title' => 'Enviar relatório', 'assignee' => 'Ana', 'dueDate' => 'sexta'],
            ['title' => 'Agendar reunião com o cliente', 'assignee' => '', 'dueDate' => ''],
        ], $this->extract($text));
    }

    public function testNoTasksMessage(): void {
        $this->assertSame([], $this->extract("TAREFAS: Nenhuma tarefa identificada."));
    }

    public function testBulletsOutsideTasksSectionAreIgnored(): void {
        $this->assertSame([], $this->extract("## Pauta\n- Orçamento 2026\n- Contratações"));
    }

    /** R11: the same task headings count for bullet sections and tables, in pt/en/es. */
    /** @dataProvider taskHeadings */
    public function testBulletSectionHeadingsInThreeLanguages(string $heading): void {
        $text = "## Resumo\n- não é tarefa\n\n## {$heading}\n- Enviar a proposta - Responsável: Ana\n";
        $this->assertSame([['title' => 'Enviar a proposta', 'assignee' => 'Ana', 'dueDate' => '']], $this->extract($text));
    }

    public static function taskHeadings(): array {
        return array_map(fn ($h) => [$h], [
            'Próximos passos', 'Ações', 'Acoes', 'Encaminhamentos', 'Tarefas',
            'Next steps', 'Action items', 'Actions', 'Tasks',
            'Próximos pasos', 'Acciones', 'Tareas',
        ]);
    }

    /** @dataProvider tableHeaders */
    public function testTableHeadersInThreeLanguages(string $header): void {
        $text = "| {$header} | Responsável | Prazo |\n|---|---|---|\n| Enviar a proposta | Ana | sexta |\n";
        $this->assertSame([['title' => 'Enviar a proposta', 'assignee' => 'Ana', 'dueDate' => 'sexta']], $this->extract($text));
    }

    public static function tableHeaders(): array {
        return array_map(fn ($h) => [$h], ['Tarefa', 'Ação', 'Próximo passo', 'Task', 'Action item', 'Next step', 'Tarea', 'Acción', 'Próximo paso']);
    }
}
