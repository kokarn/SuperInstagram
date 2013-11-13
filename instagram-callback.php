<?php
    function FindWPConfig( $directory ){
        global $confroot;

        foreach( glob( $directory . "/*" ) as $f ) :
            if ( basename( $f ) == 'wp-config.php' ) :
                $confroot = str_replace( "\\", "/", dirname( $f ) );
                return true;
            endif;
            if ( is_dir( $f ) ) :
                $newdir = dirname(dirname($f));
            endif;
        endforeach;

        if ( isset( $newdir ) && $newdir != $directory ) :
            if ( FindWPConfig( $newdir ) ) :
                return false;
            endif;
        endif;
        return false;
    }

    if( isset( $_GET[ 'hub_challenge' ] ) && isset( $_GET[ 'hub_verify_token' ] ) && $_GET[ 'hub_verify_token' ] == 'hashtagInstagramSubscription' ) :
        $challenge = $_GET[ 'hub_challenge' ];
        die( $challenge );
    endif;

    if ( !isset( $table_prefix ) ):
        global $confroot;
        FindWPConfig( dirname( dirname( __FILE__ ) ) );
        include_once $confroot . "/wp-load.php";
    endif;

    $instagram = new SnmlInstagram();

    if( isset( $_GET['code'] ) ) :
        $instagram->authenticate( $_GET['code'] );
    else :
        $instagram->handleSubscription();
    endif;