<?php
return array(    
    'EPUL_USERNAME'  => array(
        'value'        => '',
        'title'        => _wp('EpulUsername'),
        'description'  => _wp('EpulUsernameDesc'),
        'control_type' => 'input',        
    ),
    'EPUL_PASSWORD'    => array(
        'value'        => '',
        'title'        => _wp('EpulSecretKey'),
        'description'  => '',
        'control_type' => waHtmlControl::PASSWORD,
    )
);
