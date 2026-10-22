<?php

return [
    /*
    |--------------------------------------------------------------------------
    | eduXid - Analisis Butir Soal (CTT)
    |--------------------------------------------------------------------------
    |
    | Nilai di bawah adalah PEDOMAN psikometri yang dapat dikonfigurasi,
    | bukan ambang wajib regulasi nasional.
    |
    */
    'upper_lower_fraction' => 0.27,

    'difficulty' => [
        'difficult_max' => 0.29,
        'moderate_min' => 0.30,
        'moderate_max' => 0.70,
        'easy_min' => 0.71,
    ],

    'discrimination' => [
        'very_good_min' => 0.40,
        'good_min' => 0.30,
        'review_min' => 0.20,
    ],

    'point_biserial' => [
        'good_min' => 0.30,
        'review_min' => 0.20,
    ],

    'distractor' => [
        'minimum_share' => 0.05,
    ],

    'sample' => [
        'very_small_max' => 19,
        'caution_max' => 29,
        'classroom_max' => 49,
    ],
];
