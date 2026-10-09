<?php

namespace App\Http\Requests\Finance;

use App\Queries\Reports\MonthlyReportQuery;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

class MonthlyReportRequest extends FinanceRequest
{
    private const MONTH = 'regex:/^(20[0-9]{2}|2100)-(0[1-9]|1[0-2])$/';

    public function rules(): array
    {
        return [
            'from' => ['sometimes', 'required', 'string', self::MONTH],
            'to' => ['sometimes', 'required', 'string', self::MONTH],
            'organization_id' => ['prohibited'],
            'timezone' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        $message = 'Informe um mês entre 2000-01 e 2100-12, no formato ano-mês.';

        return ['from.regex' => $message, 'to.regex' => $message];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }
            [$from, $to] = $this->months();
            if ($to < $from) {
                $validator->errors()->add('to', 'O mês final não pode ser anterior ao inicial.');
            } elseif (CarbonImmutable::createFromFormat('!Y-m', $from)->diffInMonths(CarbonImmutable::createFromFormat('!Y-m', $to)) + 1 > MonthlyReportQuery::MAX_MONTHS) {
                $validator->errors()->add('to', 'Escolha até '.MonthlyReportQuery::MAX_MONTHS.' meses.');
            }
        }];
    }

    /**
     * Defaults to the last six local months, ending in the current one. A single bound keeps six months.
     *
     * @return array{0: string, 1: string}
     */
    public function months(): array
    {
        $from = $this->input('from');
        $to = $this->input('to');
        $current = CarbonImmutable::now($this->organizationTimezone())->format('Y-m');
        if (! is_string($to)) {
            $to = is_string($from) ? max($from, min($current, CarbonImmutable::createFromFormat('!Y-m', $from)->addMonths(5)->format('Y-m'))) : $current;
        }
        if (! is_string($from)) {
            $from = CarbonImmutable::createFromFormat('!Y-m', $to)->subMonths(5)->format('Y-m');
        }

        return [$from, $to];
    }
}
