<?php
/*
$_GET
$_POST
$_FILES
$_COOKIE
$_SESSION
$_REQUEST
*/

return [
    'fields' => [
        'q' => ['sql'],
        'userQuestion' => [
            'xss',
            'db_field' => 'faq.question'
        ],
        'userEmail' => [
            'xss', 'sql',
            'db_field' => 'faq.email'
        ]
    ],

    'vulnerabilities' => [
        'xss' => [
            'enabled' => true,
            'stored' => true
        ],

        'sql' => [
            'blind' => false
        ],

        'csrf' => [
            'enabled' => false
        ]
    ]
];


//$get = $_GET;
//$post = $_POST;
//
//
//return array(
//        'get' => $get,
//        'post'  => $post,
//        'inputs' => array('userQuestion' => array('sql'), 'userEmail' => array('sql')),
//
//        'sql' => array('select'=>array(
//                                   'Double-up Single Quotes' => true, /*true-false*/
//                                   'SanitizationLevel' => 'none',
//                                   'PatternMatchingStyle' => 'Keywords',
//                                   'SanitizationParameters' => array()
//                                   ),
//                        'insert'=>array(
//                                   'Double-up Single Quotes' => true, /*true-false*/
//                                   'SanitizationLevel' => 'none',
//                                   'PatternMatchingStyle' => 'Keywords',
//                                   'SanitizationParameters' => array()
//                                   ),
//                      ),
//
//         'xss' => array(
//
//                      ),
//);