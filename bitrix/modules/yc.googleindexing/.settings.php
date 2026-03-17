<?php
/**
 * Настройки модуля Google Indexing API PRO
 */

return [
    'main' => [
        'title' => 'Основные настройки',
        'fields' => [
            'DAILY_LIMIT' => [
                'title' => 'Дневной лимит отправок',
                'type' => 'number',
                'value' => 200,
                'note' => 'Количество запросов в сутки. По умолчанию 200 (лимит Google).',
            ],
            'BATCH_SIZE' => [
                'title' => 'Размер пачки за один цикл',
                'type' => 'number',
                'value' => 50,
                'note' => 'Количество URL для отправки за один запуск агента.',
            ],
            'AGENT_INTERVAL' => [
                'title' => 'Интервал агента (сек)',
                'type' => 'number',
                'value' => 300,
                'note' => 'Как часто запускается агент (по умолчанию каждые 5 минут).',
            ],
        ],
    ],
    'iblock' => [
        'title' => 'Настройки инфоблоков',
        'fields' => [
            'TRACK_ELEMENTS' => [
                'title' => 'Отслеживать элементы',
                'type' => 'checkbox',
                'value' => 'Y',
                'note' => 'Добавлять URL элементов инфоблоков в очередь при создании/обновлении/удалении.',
            ],
            'TRACK_SECTIONS' => [
                'title' => 'Отслеживать разделы',
                'type' => 'checkbox',
                'value' => 'N',
                'note' => 'Добавлять URL разделов инфоблоков в очередь.',
            ],
            'IBLOCKS' => [
                'title' => 'Выбранные инфоблоки',
                'type' => 'multiselect',
                'value' => '',
                'note' => 'Оставьте пустым для отслеживания всех инфоблоков.',
                'params' => [
                    'multiple' => 'multiple',
                    'size' => 5,
                ],
            ],
        ],
    ],
    'logging' => [
        'title' => 'Логирование',
        'fields' => [
            'LOG_SUCCESS' => [
                'title' => 'Логировать успешные запросы',
                'type' => 'checkbox',
                'value' => 'N',
                'note' => 'Записывать в лог каждый успешный запрос к Google API.',
            ],
            'LOG_RETENTION_DAYS' => [
                'title' => 'Хранение логов (дней)',
                'type' => 'number',
                'value' => 30,
                'note' => 'Сколько дней хранить записи логов.',
            ],
        ],
    ],
];
