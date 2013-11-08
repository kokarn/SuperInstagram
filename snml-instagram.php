<?php
    /*
    Plugin Name: Supernormal instagram
    Description: Creates posts from a instagram hashtag
    Version: 0.1
    Author: Supernormal
    Author URI: http://supernormal.se
    */

    class SnmlInstagram {
        private static $prefix = 'snml_instagram';
        private static $textDomain = 'snml-instagram';
        private static $instagramApiBaseUrl = 'https://api.instagram.com/v1/';
        private static $instagramSubscriptionsBaseUrl = 'https://api.instagram.com/v1/subscriptions/';
        private $createPostResults = array();

        public function __construct(){
            $this->setupHooks();
        }

        private function setupHooks(){

            register_activation_hook( __FILE__, array( $this, 'flushRewriteRules' ) );

            add_action( 'init', array( $this, 'registerCustomPostType' ) );
            add_action( 'init', array( $this, 'registerCustomTaxonomies' ) );
            add_action( 'admin_menu', array( $this, 'createOptionsPage' ) );

            add_action( 'add_meta_boxes', array( $this, 'registerMetaBoxes' ) );

        }

        public function flushRewriteRules(){
            $this->registerCustomPostType();

            flush_rewrite_rules();
        }

        public function registerCustomPostType(){
            $arguments = array(
                'public' => true,
                'label'  => 'Instagram',
                'supports' => array(
                    'title'
                ),
                'menu_icon' => plugin_dir_url( __FILE__ ) . 'images/instagram-logo-icon.png'
            );
            register_post_type( 'instagram', $arguments );
        }

        public function registerCustomTaxonomies(){
            $filtersLabels = array(
                'name' => __( 'Filters', self::$textDomain ),
                'singular_name' => __( 'Filter', self::$textDomain )
            );

            $filtersArguments = array(
                'hierarchial' => false,
                'labels' => $filtersLabels
            );

            register_taxonomy( 'filters', 'instagram', $filtersArguments );

            $tagsLabels = array(
                'name' => __( 'Tags', self::$textDomain ),
                'singular_name' => __( 'Tag', self::$textDomain )
            );

            $tagsArguments = array(
                'hierarchial' => false,
                'labels' => $tagsLabels
            );

            register_taxonomy( 'tags', 'instagram', $tagsArguments );
        }

        public function createOptionsPage(){
            add_submenu_page( 'edit.php?post_type=instagram', __( 'Settings', self::$textDomain ) , __( 'Settings', self::$textDomain ), 'manage_options', 'settings', array( $this, 'optionsPage' ) );
        }

        public function registerMetaBoxes(){
            add_meta_box(
                'instagram-meta',
                __( 'Instagram data', self::$textDomain ),
                array( $this, 'metaboxData' ),
                'instagram'
            );
        }

        public function metaBoxData(){
            $meta = get_post_meta( get_the_ID() );
            ?>
            <table>
                <?php
                    foreach( $meta as $key => $value ) :
                        if( strpos( $key, 'instagram-' ) !== 0 ) :
                            continue;
                        endif;
                        $displayKey = str_replace( 'instagram-', '', $key );
                        ?>
                            <tr>
                                <td>
                                    <?php
                                        echo $displayKey;
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $storedValue = reset( $value );
                                        $realData = json_decode( $storedValue );

                                        if( $realData === null ) :
                                            echo $storedValue;
                                        else :
                                            ?>
                                            <pre><?php
                                                print_r( $realData );
                                            ?></pre>
                                            <?php
                                        endif;
                                    ?>
                                </td>
                            </tr>
                        <?php
                    endforeach;
                ?>
            </table>

            <?php
        }

        public function handleSubscription( $debug = false ){
            $fullUrl = self::$instagramApiBaseUrl . 'tags/' . $this->getHashtag() . '/media/recent?client_id=' . $this->getClientId();
            $response = file_get_contents( $fullUrl );

            if( !$debug ) :
                $this->saveResponse( $response );
            else :
                $this->printResponse( $response );
            endif;
        }

        public function loadAllPosts( $fullUrl = false ){

            if( !$fullUrl ) :
                $fullUrl = self::$instagramApiBaseUrl . 'tags/' . $this->getHashtag() . '/media/recent?client_id=' . $this->getClientId();
            endif;

            $response = file_get_contents( $fullUrl );

            if( !$this->saveResponse( $response ) ):
                // Failed to save responses (invalid json)
                return false;
            endif;

            $extractData = json_decode( $response );

            if( !isset( $extractData->pagination ) ) :
                // No pagination set
                return true;
            endif;

            if( !isset( $extractData->pagination->next_url ) ) :
                // No more next url
                return true;
            endif;

            // Pagination and next_url set
            $this->loadAllPosts( $extractData->pagination->next_url );

            return true;
        }

        public function handleLoadAllPosts(){
            $this->loadAllPosts();
            $this->printMessage( $this->createPostResults );
        }

        private function saveResponse( $response ){
            $parsedResponse = json_decode( $response );

            if( $parsedResponse === null ) :
                return false;
            endif;

            foreach( $parsedResponse->data as $media ) :
                $this->createPost( $media );
            endforeach;

            return true;
        }

        private function addPostResult( $key, $message ){
            $this->createPostResults[] = array(
                'key' => $key,
                'message' => $message
            );
        }

        private function parseTitle( $string ){
            $parsedTitle = $this->instagramClean( $string );
            $parsedTitle = apply_filters( 'the_title', $parsedTitle );
            $parsedTitle = preg_replace( '/\s+/', ' ', $parsedTitle );
            //$parsedTitle = preg_replace( '/\xEE[\x80-\xBF][\x80-\xBF]|\xEF[\x81-\x83][\x80-\xBF]/', '', $parsedTitle );
            $parsedTitle = trim( $parsedTitle );

            return $parsedTitle;
        }

        private function instagramClean( $text ) {

            $clean_text = "";

            // Match Emoticons
            $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
            $clean_text = preg_replace($regexEmoticons, '', $text);

            // Match Miscellaneous Symbols and Pictographs
            $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
            $clean_text = preg_replace($regexSymbols, '', $clean_text);

            // Match Transport And Map Symbols
            $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
            $clean_text = preg_replace($regexTransport, '', $clean_text);

            return $clean_text;
        }

        private function createPost( $media ){

            if( $this->postExists( $media->id ) ) :
                $this->addPostResult( $media->id, 'Post already exists' );
                return false;
            endif;

            $parsedTitle = $this->parseTitle( $media->caption->text );

            $post = array(
                'post_type' => 'instagram',
                'post_title' => $parsedTitle,
                'post_status' => 'publish'
            );

            $postMeta = array();

            $postMeta[ 'instagram-id' ] = $media->id;
            $postMeta[ 'instagram-username' ] = $media->user->username;
            $postMeta[ 'instagram-full_name' ] = $media->user->full_name;
            $postMeta[ 'instagram-link' ] = $media->link;
            $postMeta[ 'instagram-images' ] = json_encode( $media->images );

            if( !empty( $media->location ) ) :
                $postMeta[ 'location' ] = json_encode( $media->location );
            endif;

            $postId = wp_insert_post( $post );

            foreach( $postMeta as $key => $value ) :
                update_post_meta( $postId, $key, $value );
            endforeach;

            wp_set_object_terms( $postId, $media->filter, 'filters', true );
            wp_set_object_terms( $postId, $media->tags, 'tags', true );

            $this->addPostResult( $media->key, 'Post added' );

            return true;
        }

        private function postExists( $instagramId ){
            $postArgs = array(
                'meta_key' => 'instagram-id',
                'meta_value' => $instagramId,
                'post_type' => 'instagram'
            );

            $instagramPost = new WP_Query( $postArgs );
            return $instagramPost->have_posts();
        }

        private function createSubscription(){
            $vars = array(
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'object' => 'tag',
                'object_id' => $this->getHashtag(),
                'aspect' => 'media',
                'verify_token' => 'hashtagInstagramSubscription',
                'callback_url' => plugin_dir_url( __FILE__ ) . 'instagram-callback.php'
            );

            $fields_string = '';

            foreach( $vars as $key => $value) :
                $fields_string .= $key .'='. $value . '&';
            endforeach;

            rtrim( $fields_string, '&' );

            //open connection
            $ch = curl_init();

            //set the url, number of POST vars, POST data
            curl_setopt( $ch, CURLOPT_URL, self::$instagramSubscriptionsBaseUrl );
            curl_setopt( $ch, CURLOPT_POST, count( $vars ) );
            curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

            //execute post
            $result = curl_exec($ch);

            //close connection
            curl_close($ch);

            $parsedData = json_decode( $result );

            if( $parsedData->meta->code === 200 ) :
                $this->setSubscriptionId( $parsedData->data->id );
            endif;

            $this->printResponse( $result );
        }

        private function cancelSubscription( $subscriptionId = 0 ){
            if( $subscriptionId <= 0 ) :
                $subscriptionId = $this->getSubscriptionId();
            endif;

            $curlUrl = self::$instagramSubscriptionsBaseUrl . '?client_id=' . $this->getClientId() . '&client_secret=' . $this->getClientSecret() . '&id=' . $subscriptionId;

            //open connection
            $ch = curl_init();

            //set the url, number of POST vars, POST data
            curl_setopt( $ch, CURLOPT_URL, $curlUrl );
            curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );

            //execute post
            $result = curl_exec($ch);

            //close connection
            curl_close($ch);

            $this->setSubscriptionId( 0 );

            $this->printResponse( $result );
        }

        private function printResponse( $result ){
            $jsonResult = json_decode( $result );
            if( $jsonResult !== null ) :
                $message = $jsonResult;
                if( $jsonResult->meta->code === 200 ) :
                    $class = 'updated';
                else :
                    $class = 'error';
                endif;
            else :
                $class = 'updated';
                $message = $result;
            endif;
            $this->printMessage( $message, $class );
        }

        private function printMessage( $message, $class = 'updated' ){
            ?>
            <div id="message" class="<?php echo $class; ?> below-h2">
                <pre><?php
                        print_R( $message );
                    ?>
                </pre>
            </div>
            <?php
        }

        private function clearPosts(){
            $havePosts = true;
            $queryArgs = array(
                'post_type' => 'instagram',
                'posts_per_page' => '100'
            );

            while( $havePosts ) :
                $postsQuery = new WP_Query( $queryArgs );

                if( !$postsQuery->have_posts() ) :
                    $havePosts = false;
                endif;

                while( $postsQuery->have_posts() ) :
                    $postsQuery->the_post();
                    wp_delete_post( get_the_ID() );
                endwhile;
            endwhile;
        }

        public function optionsPage(){
            ?>
            <div class="wrap">
                <h2>
                    Supernormal Instagram
                </h2>
                <?php
                    if( isset( $_POST ) AND !empty( $_POST ) ) :
                        if( isset( $_POST[ 'action' ] ) ) :
                            switch( $_POST[ 'action' ] ) :
                                case 'options':
                                    $this->saveOptions();
                                    break;
                                case 'subscription':
                                    $this->createSubscription();
                                    break;
                                case 'delete':
                                    $this->cancelSubscription( $_POST[ 'manualId' ] );
                                    break;
                                case 'debug':
                                    $this->handleSubscription( $debug = true );
                                    break;
                                case 'empty':
                                    $this->clearPosts();
                                    break;
                                case 'loadall':
                                    $this->handleLoadAllPosts();
                                    break;
                            endswitch;
                        endif;
                    endif;
                ?>
                <h3>
                    <?php
                        _e( 'Settings', self::$textDomain );
                    ?>
                </h3>
                <form method="post">
                    <input type="hidden" name="action" value="options">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label>
                                    <?php
                                        _e( 'Client ID', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="client-id" placeholder="<?php _e( 'Client Id', self::$textDomain ); ?>" value="<?php echo $this->getClientId(); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>
                                    <?php
                                        _e( 'Client secret', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="client-secret" placeholder="<?php _e( 'Client secret', self::$textDomain ); ?>" value="<?php echo $this->getClientSecret(); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label>
                                    <?php
                                        _e( 'Selected hashtag', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                #<input type="text" name="hashtag" placeholder="<?php _e( 'hashtag without #', self::$textDomain ); ?>" value="<?php echo $this->getHashtag(); ?>">
                                <em>
                                    <?php
                                        _e( 'It will be sanitized', self::$textDomain );
                                    ?>
                                </em>
                            </td>
                        </tr>
                        <tr>
                            <td>
                            </td>
                            <td>
                                <button type="submit" class="button button-primary">
                                    <?php
                                        _e( 'Save', self::$textDomain );
                                    ?>
                                </button>
                            </td>
                        </tr>
                    </table>
                </form>
                <h3>
                    <?php
                        _e( 'Posts', self::$textDomain );
                    ?>
                </h3>
                <p>
                    <form method="post">
                        <input type="hidden" name="action" value="loadall">
                        <button type="submit" class="button-secondary">
                            <?php
                                _e( 'Load posts', self::$textDomain );
                            ?>
                        </button>
                        <em>
                            <?php
                                _e( 'This will load all posts with the selected hashtag', self::$textDomain );
                            ?>
                        </em>
                    </form>
                </p>
                <p>
                    <form method="post">
                        <input type="hidden" name="action" value="empty">
                        <button type="submit" class="button-secondary">
                            <?php
                                _e( 'Clear posts', self::$textDomain );
                            ?>
                        </button>
                        <em>
                            <?php
                                _e( 'This will remove all posts in the Custom Post type', self::$textDomain );
                            ?>
                        </em>
                    </form>
                </p>
                <h3>
                    <?php
                        _e( 'Subscription', self::$textDomain );
                    ?>
                </h3>
                <?php
                    if( !$this->getSubscriptionId() ) :
                        ?>
                        <h4>
                            <?php
                                _e( 'No current subscription', self::$textDomain );
                            ?>
                        </h4>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="subscription">
                            <button type="submit" class="button button-primary">
                                <?php
                                    _e( 'Create Subscription', self::$textDomain );
                                ?>
                            </button>
                        </form>
                        <?php
                    else :
                        ?>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="delete">
                            <button type="submit" class="button button-secondary">
                                <?php
                                    _e( 'Cancel subscription', self::$textDomain );
                                ?>
                            </button>
                        </form>
                        <h4>
                            <?php
                                _e( 'Current subscription', self::$textDomain );
                            ?>
                        </h4>
                        <pre><?php
                                print_r( json_decode(  $this->getSubscriptions() ) );
                            ?>
                        </pre>
                        <?php
                    endif;
                ?>
                <h3>
                    <?php
                        _e( 'Debug', self::$textDomain );
                    ?>
                </h3>
                <p>
                    <form method="post">
                        <input type="hidden" name="action" value="debug">
                        <button type="submit" class="button button-secondary">
                            <?php
                                _e( 'Test request', self::$textDomain );
                            ?>
                        </button>
                    </form>
                </p>
            </div>
            <?php
        }

        private function saveOptions(){
            if( isset( $_POST[ 'client-id' ] ) ) :
                $this->setClientId( $_POST[ 'client-id' ] );
            endif;

            if( isset( $_POST[ 'client-secret' ] ) ) :
                $this->setClientSecret( $_POST[ 'client-secret' ] );
            endif;

            if( isset( $_POST[ 'hashtag' ] ) ) :
                $this->setHashtag( $_POST[ 'hashtag' ] );
            endif;
        }

        private function getSubscriptions(){
            $url = self::$instagramSubscriptionsBaseUrl . '?client_secret=' . $this->getClientSecret() . '&client_id=' . $this->getClientId();
            $data = file_get_contents( $url );
            return $data;
        }

        private function getClientId(){
            return get_option( self::$prefix . '_client_id' );
        }

        private function setClientId( $clientId ){
            return update_option( self::$prefix . '_client_id', $clientId );
        }

        private function getClientSecret(){
            return get_option( self::$prefix . '_client_secret' );
        }

        private function setClientSecret( $clientSecret ){
            return update_option( self::$prefix . '_client_secret', $clientSecret );
        }

        private function getHashtag(){
            return get_option( self::$prefix . '_hashtag' );
        }

        private function setHashtag( $hashtag ){
            $hashtag = strtolower( $hashtag );
            $hashtag = preg_replace( '/[^a-z0-9äåö]/', '', $hashtag );
            return update_option( self::$prefix . '_hashtag', $hashtag );
        }

        private function getSubscriptionId(){
            return get_option( self::$prefix . '_subscription_id' );
        }

        private function setSubscriptionId( $subscriptionId ){
            return update_option( self::$prefix . '_subscription_id', $subscriptionId );
        }
    }

    new SnmlInstagram();
