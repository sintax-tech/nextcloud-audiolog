<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Audiolog\Tests\Unit\Support;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

/**
 * Tiny in-memory user storage built from PHPUnit mocks of the OCP Files
 * interfaces. Paths are absolute ("/alice/files/Audiolog/x.webm"); the user
 * folder is "/<uid>/files". Supports what AudioService uses: get, nodeExists,
 * newFile, newFolder, getDirectoryListing, move, delete, put/getContent.
 */
final class FakeFs {
    /** @var array<string,array{type:string,content?:string,mime?:string,id:int}> */
    public array $nodes = [];
    private int $nextId = 100;

    public function __construct(private TestCase $tc, private string $uid = 'alice') {
        $this->nodes[$this->home()] = ['type' => 'dir', 'id' => $this->nextId++];
    }

    public function home(): string {
        return '/' . $this->uid . '/files';
    }

    public function addFile(string $rel, string $content = 'x', string $mime = 'audio/webm'): void {
        $this->ensureDir(dirname($this->home() . '/' . $rel));
        $this->nodes[$this->home() . '/' . $rel] = ['type' => 'file', 'content' => $content, 'mime' => $mime, 'id' => $this->nextId++];
    }

    public function addDir(string $rel): void {
        $this->ensureDir($this->home() . '/' . $rel);
    }

    public function exists(string $rel): bool {
        return isset($this->nodes[$this->home() . '/' . $rel]);
    }

    public function content(string $rel): ?string {
        return $this->nodes[$this->home() . '/' . $rel]['content'] ?? null;
    }

    public function rootFolder(): IRootFolder {
        $root = $this->mock(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn () => $this->node($this->home()));
        return $root;
    }

    private function ensureDir(string $abs): void {
        while ($abs !== '/' && !isset($this->nodes[$abs])) {
            $this->nodes[$abs] = ['type' => 'dir', 'id' => $this->nextId++];
            $abs = dirname($abs);
        }
    }

    private function mock(string $class): object {
        return (new \ReflectionMethod($this->tc, 'createMock'))->invoke($this->tc, $class);
    }

    public function node(string $abs): File|Folder {
        if (!isset($this->nodes[$abs])) {
            throw new NotFoundException($abs);
        }
        $isDir = $this->nodes[$abs]['type'] === 'dir';
        $n = $this->mock($isDir ? Folder::class : File::class);
        // Path follows moves: resolve through the id, not the captured path.
        $id = $this->nodes[$abs]['id'];
        $pathOf = function () use ($id, $abs): string {
            foreach ($this->nodes as $p => $info) {
                if ($info['id'] === $id) {
                    return $p;
                }
            }
            return $abs;
        };
        $n->method('getPath')->willReturnCallback($pathOf);
        $n->method('getName')->willReturnCallback(fn () => basename($pathOf()));
        $n->method('getId')->willReturn($id);
        $n->method('getMTime')->willReturn(1700000000);
        $n->method('getSize')->willReturnCallback(fn () => strlen($this->nodes[$pathOf()]['content'] ?? ''));
        $n->method('getParent')->willReturnCallback(fn () => $this->node(dirname($pathOf())));
        $n->method('delete')->willReturnCallback(function () use ($pathOf) {
            $p = $pathOf();
            foreach (array_keys($this->nodes) as $k) {
                if ($k === $p || str_starts_with($k, $p . '/')) {
                    unset($this->nodes[$k]);
                }
            }
        });
        $n->method('move')->willReturnCallback(function (string $target) use ($pathOf) {
            $p = $pathOf();
            if (isset($this->nodes[$target])) {
                throw new \OCP\Files\NotPermittedException('target exists: ' . $target);
            }
            $moved = [];
            foreach ($this->nodes as $k => $v) {
                if ($k === $p || str_starts_with($k, $p . '/')) {
                    $moved[$target . substr($k, strlen($p))] = $v;
                    unset($this->nodes[$k]);
                }
            }
            $this->nodes += $moved;
            return $this->node($target);
        });
        if ($isDir) {
            $n->method('get')->willReturnCallback(fn (string $rel) => $this->node($this->join($pathOf(), $rel)));
            $n->method('nodeExists')->willReturnCallback(fn (string $rel) => isset($this->nodes[$this->join($pathOf(), $rel)]));
            $n->method('newFolder')->willReturnCallback(function (string $rel) use ($pathOf) {
                $this->ensureDir($this->join($pathOf(), $rel));
                return $this->node($this->join($pathOf(), $rel));
            });
            $n->method('newFile')->willReturnCallback(function (string $rel, $content = null) use ($pathOf) {
                $abs = $this->join($pathOf(), $rel);
                $mime = str_ends_with($abs, '.md') ? 'text/markdown' : (str_ends_with($abs, '.json') ? 'application/json' : 'application/octet-stream');
                $this->nodes[$abs] = ['type' => 'file', 'content' => (string)$content, 'mime' => $mime, 'id' => $this->nextId++];
                return $this->node($abs);
            });
            $n->method('getDirectoryListing')->willReturnCallback(function () use ($pathOf) {
                $p = $pathOf();
                $out = [];
                foreach (array_keys($this->nodes) as $k) {
                    if (dirname($k) === $p && $k !== $p) {
                        $out[] = $this->node($k);
                    }
                }
                return $out;
            });
        } else {
            $n->method('getContent')->willReturnCallback(fn () => $this->nodes[$pathOf()]['content']);
            $n->method('putContent')->willReturnCallback(function ($c) use ($pathOf) {
                $this->nodes[$pathOf()]['content'] = (string)$c;
            });
            $n->method('getMimeType')->willReturnCallback(fn () => $this->nodes[$pathOf()]['mime']);
        }
        return $n;
    }

    private function join(string $base, string $rel): string {
        return rtrim($base, '/') . '/' . trim($rel, '/');
    }
}
