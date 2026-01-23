<?php

return [
    'max_by_plan' => [
        'free' => 0,
        'starter' => 1,
        'pro' => 3,
    ],


    'limits_by_plan' => [
    'free' => ['max_content' => 0,  'max_report' => 0, 'max_activations' => 0],
    'starter' => ['max_content' => 10, 'max_report' => 1, 'max_activations' => 1],
    'pro' => ['max_content' => 30, 'max_report' => 3, 'max_activations' => 3],
  ],
];