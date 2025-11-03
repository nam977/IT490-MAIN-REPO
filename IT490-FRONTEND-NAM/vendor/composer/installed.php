<?php return array(
    'root' => array(
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'reference' => NULL,
        'name' => '__root__',
        'dev' => true,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'reference' => NULL,
            'dev_requirement' => false,
        ),
        'php-amqplib/php-amqplib' => array(
            'pretty_version' => 'v2.12.1',
            'version' => '2.12.1.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../php-amqplib/php-amqplib',
            'aliases' => array(),
            'reference' => '0eaaa9d5d45335f4342f69603288883388c2fe21',
            'dev_requirement' => false,
        ),
        'phpseclib/phpseclib' => array(
            'pretty_version' => '2.0.49',
            'version' => '2.0.49.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../phpseclib/phpseclib',
            'aliases' => array(),
            'reference' => '4de468f48f0ab9709fc875aca0762abdc81cfa9b',
            'dev_requirement' => false,
        ),
        'videlalvaro/php-amqplib' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => 'v2.12.1',
            ),
        ),
    ),
);
