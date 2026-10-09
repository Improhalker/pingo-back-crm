<?php

namespace App\Internal\Kanban\Http\Requests;

class StoreKanbanTaskRequest extends KanbanRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Informe um título.', 'title.max' => 'O título aceita até 120 caracteres.', 'description.max' => 'A descrição aceita até 2.000 caracteres.'];
    }
}
