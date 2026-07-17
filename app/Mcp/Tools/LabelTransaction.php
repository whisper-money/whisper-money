<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Add and/or remove labels on any transaction, including bank/imported ones. Pass add_label_ids and/or remove_label_ids.')]
class LabelTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to label.')->required(),
            'add_label_ids' => $schema->array()->items($schema->string())->description('Label ids to attach.'),
            'remove_label_ids' => $schema->array()->items($schema->string())->description('Label ids to detach.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $add = $this->labelsInSpace($request, $space, 'add_label_ids');
        $remove = $this->labelsInSpace($request, $space, 'remove_label_ids');

        if ($add->isEmpty() && $remove->isEmpty()) {
            return Response::error('Provide add_label_ids and/or remove_label_ids (arrays of label ids).');
        }

        if ($add->isNotEmpty()) {
            $transaction->labels()->syncWithoutDetaching($add->pluck('id')->all());
        }

        if ($remove->isNotEmpty()) {
            $transaction->labels()->detach($remove->pluck('id')->all());
        }

        return $this->json(['transaction' => $this->presentTransaction($transaction->refresh())]);
    }
}
