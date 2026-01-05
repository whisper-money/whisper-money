<?php

namespace App\Services\Demo;

class DemoAutomationRulesProvider
{
    /**
     * Get demo automation rules.
     * Each rule references a category name that will be resolved during seeding.
     *
     * @return array<int, array{title: string, priority: int, rules_json: array<string, mixed>, category_name: string|null, action_note: string|null}>
     */
    public function getRules(): array
    {
        return [
            [
                'title' => 'Categorize Grocery Stores',
                'priority' => 10,
                'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
                'category_name' => 'Groceries',
                'action_note' => null,
            ],
            [
                'title' => 'Categorize Coffee Shops',
                'priority' => 20,
                'rules_json' => ['or' => [
                    ['in' => ['starbucks', ['var' => 'description']]],
                    ['in' => ['coffee', ['var' => 'description']]],
                ]],
                'category_name' => 'Cafes, restaurants, bars',
                'action_note' => null,
            ],
            [
                'title' => 'Categorize Gas Stations',
                'priority' => 30,
                'rules_json' => ['or' => [
                    ['in' => ['gas', ['var' => 'description']]],
                    ['in' => ['fuel', ['var' => 'description']]],
                    ['in' => ['shell', ['var' => 'description']]],
                ]],
                'category_name' => 'Fuel',
                'action_note' => null,
            ],
            [
                'title' => 'Categorize Salary Deposits',
                'priority' => 5,
                'rules_json' => ['and' => [
                    ['>' => [['var' => 'amount'], 0]],
                    ['in' => ['salary', ['var' => 'description']]],
                ]],
                'category_name' => 'Salary',
                'action_note' => null,
            ],
            [
                'title' => 'Categorize Online Subscriptions',
                'priority' => 40,
                'rules_json' => ['or' => [
                    ['in' => ['netflix', ['var' => 'description']]],
                    ['in' => ['spotify', ['var' => 'description']]],
                    ['in' => ['subscription', ['var' => 'description']]],
                ]],
                'category_name' => 'Online services',
                'action_note' => null,
            ],
        ];
    }
}
