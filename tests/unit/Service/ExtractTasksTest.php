<?php
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
}
