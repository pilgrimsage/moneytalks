<?php

namespace App\Services\AI;

use App\Models\PromptTemplate;
use App\Services\AI\Prompts\ReceiptParser;
use App\Services\AI\Prompts\TransactionParser;

/**
 * Prompts are versioned rows in prompt_templates. The built-in prompt is synced into the table the first
 * time it is needed; when a NEW built-in version appears it becomes the active one and the older built-in
 * rows are retired. A row created by an admin (source = admin) that is activated later wins over the built-in.
 */
class PromptRegistry
{
    public function active(string $name = TransactionParser::NAME): PromptTemplate
    {
        [$version, $body, $schemaVersion] = match ($name) {
            TransactionParser::NAME => [TransactionParser::VERSION, TransactionParser::system(), TransactionParser::SCHEMA_VERSION],
            ReceiptParser::NAME => [ReceiptParser::VERSION, ReceiptParser::system(), ReceiptParser::SCHEMA_VERSION],
            default => throw new \InvalidArgumentException("Unknown prompt {$name}"),
        };

        $builtin = PromptTemplate::where('name', $name)->where('version', $version)->first();
        if (! $builtin) {
            PromptTemplate::where('name', $name)->where('source', 'builtin')->where('status', 'active')->update(['status' => 'retired']);
            $builtin = PromptTemplate::create(['name' => $name, 'version' => $version, 'body' => $body, 'schema_version' => $schemaVersion, 'status' => 'active', 'source' => 'builtin']);
        }

        return PromptTemplate::where('name', $name)->where('status', 'active')->orderByDesc('id')->first() ?? $builtin;
    }
}
