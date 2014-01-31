<?php
    /*
    Plugin Name: Supernormal Instagram
    Description: Creates posts from a Instagram hashtag or user
    Version: 0.2.3
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
            add_action( 'init', array( $this, 'authRedirect' ) );
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

            $userLabels = array(
                'name' => __( 'Users', self::$textDomain ),
                'singular_name' => __( 'User', self::$textDomain )
            );

            $userArguments = array(
                'hierarchial' => false,
                'labels' => $userLabels
            );

            register_taxonomy( 'users', 'instagram', $userArguments );
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
            $fullUrl = self::$instagramApiBaseUrl;

            // Decide whether to get recent media from a user or a hashtag
            if( $this->getUserId() ) :
                $fullUrl .= '/users/' . $this->getUserId() . '/media/recent?access_token=' . $this->getAccessToken();
            else :
                $fullUrl .= 'tags/' . $this->getHashtag() . '/media/recent?client_id=' . $this->getClientId();
            endif;

            $response = wp_remote_get( $fullUrl );

            if( is_wp_error( $response ) ) :
                return;
            endif;

            if( !$debug ) :
                $this->saveResponse( $response['body'] );
            else :
                $this->printResponse( $response );
            endif;
        }

        public function authRedirect(){
            if( isset( $_POST[ 'action' ] ) AND $_POST[ 'action' ] === 'authentication' ) :
                $fullUrl = 'https://api.instagram.com/oauth/authorize/?client_id=' . $this->getClientId() . '&redirect_uri=' . $this->getCallbackUrl() . '&response_type=code';

                wp_redirect( $fullUrl );
                die();
            endif;
        }

        public function authenticate( $code ){
            $vars = array(
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->getCallbackUrl()
            );

            $response = wp_remote_post( 'https://api.instagram.com/oauth/access_token', array(
                'body' => $vars
            ) );

            if( is_wp_error( $response ) ) :
                return;
            endif;

            $response = json_decode( $response['body'] );

            if( $response->access_token ) :
                $this->setAccessToken( $response->access_token );
            endif;

            wp_redirect( admin_url( 'edit.php?post_type=instagram&page=settings' ) );
            die();
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
            $parsedTitle = trim( $parsedTitle );

            return $parsedTitle;
        }

        private function instagramClean( $text ) {
            // Match Emoticons
            $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
            $cleanText = preg_replace( $regexEmoticons, '', $text );

            // Match Miscellaneous Symbols and Pictographs
            $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
            $cleanText = preg_replace( $regexSymbols, '', $cleanText );

            // Match Transport and Map Symbols
            $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
            $cleanText = preg_replace( $regexTransport, '', $cleanText );

            return $cleanText;
        }

        private function createPost( $media ){
            if( $this->postExists( $media->id ) ) :
                $this->addPostResult( $media->id, 'Post already exists' );
                return false;
            endif;

            $parsedTitle = $this->parseTitle( $media->caption->text );

            $post = array(
                'post_date' => date('Y-m-d H:i:s', $media->created_time),
                'post_date_gmt' => gmdate('Y-m-d H:i:s', $media->created_time),
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

            do_action( 'snml_instagram_save', $postId );

            wp_set_object_terms( $postId, $media->filter, 'filters', true );
            wp_set_object_terms( $postId, $media->tags, 'tags', true );
            wp_set_object_terms( $postId, $media->user->username, 'users', true );

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
            if( $this->getUsername() ) :
                $object = 'user';
                $object_id = '';
            else :
                $object = 'tag';
                $object_id = $this->getHashtag();
            endif;

            $vars = array(
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'object' => $object,
                'object_id' => $object_id,
                'aspect' => 'media',
                'verify_token' => 'hashtagInstagramSubscription',
                'callback_url' => $this->getCallbackUrl()
            );

            $response = wp_remote_post( self::$instagramSubscriptionsBaseUrl, array(
                'body' => $vars
            ) );

            if( is_wp_error( $response ) ) :
                return;
            endif;

            $parsedData = json_decode( $response['body'] );

            if( $parsedData->meta->code === 200 ) :
                $this->setSubscriptionId( $parsedData->data->id );
            endif;

            $this->printResponse( $response );
        }

        private function cancelSubscription( $subscriptionId = 0 ){
            if( $subscriptionId <= 0 ) :
                $subscriptionId = $this->getSubscriptionId();
            endif;

            $fullUrl = self::$instagramSubscriptionsBaseUrl . '?client_id=' . $this->getClientId() . '&client_secret=' . $this->getClientSecret() . '&id=' . $subscriptionId;

            $response = wp_remote_request( $fullUrl, array(
                'method' => 'DELETE'
            ) );

            $this->setSubscriptionId( 0 );

            $this->printResponse( $response );
        }

        private function printResponse( $response ){
            if( is_string( $response ) ) {
                $result = json_decode( $response );
            } else {
                $result = null;
            }

            if( $result !== null ) :
                $message = $result;
                if( $result->meta->code === 200 ) :
                    $class = 'updated';
                else :
                    $class = 'error';
                endif;
            else :
                $class = 'updated';
                $message = $response;
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
                                <label for="client-id">
                                    <?php
                                        _e( 'Client ID', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="client-id" id="client-id" placeholder="<?php _e( 'Client Id', self::$textDomain ); ?>" value="<?php echo $this->getClientId(); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="client-secret">
                                    <?php
                                        _e( 'Client secret', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                <input type="text" name="client-secret" id="client-secret" placeholder="<?php _e( 'Client secret', self::$textDomain ); ?>" value="<?php echo $this->getClientSecret(); ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="hashtag">
                                    <?php
                                        _e( 'Selected hashtag', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                #<input type="text" name="hashtag" id="hashtag" placeholder="<?php _e( 'hashtag without #', self::$textDomain ); ?>" value="<?php echo $this->getHashtag(); ?>">
                                <em>
                                    <?php
                                        _e( 'Will be sanitized', self::$textDomain );
                                    ?>
                                </em>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="username">
                                    <?php
                                        _e( 'Selected username', self::$textDomain );
                                    ?>
                                </label>
                            </th>
                            <td>
                                @<input type="text" name="username" id="username" placeholder="<?php _e( 'username without @', self::$textDomain ); ?>" value="<?php echo $this->getUsername(); ?>">
                                <em>
                                    <?php
                                        _e( 'Will be sanitized.', self::$textDomain );
                                    ?>

                                    <?php
                                        _e( 'Will take precedence over hashtag.', self::$textDomain );
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
                        _e( 'Authentication', self::$textDomain );
                    ?>
                </h3>
                <p>
                    <form method="post">
                        <input type="hidden" name="action" value="authentication">
                        <button type="submit" class="button button-primary">
                            <?php
                                _e( 'Authenticate', self::$textDomain );
                            ?>
                        </button>
                    </form>
                </p>
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

            if( isset( $_POST[ 'username' ] ) ) :
                $this->setUsername( $_POST[ 'username' ] );
            endif;
        }

        private function fetchUserID( $username ){
            $url = self::$instagramApiBaseUrl . 'users/search/?q=' . $username . '&client_id=' . $this->getClientId();
            $response = wp_remote_get( $url );

            if( is_wp_error( $response ) ) :
                return;
            endif;

            $response = json_decode( $response['body'] );

            $userId = 0;
            foreach( $response->data as $user ) :
                if( $user->username === $username ) :
                    $userId = $user->id;
                    break;
                endif;
            endforeach;

            $this->setUserId( $userId );

            return $userId;
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

        private function getAccessToken(){
            return get_option( self::$prefix . '_access_token' );
        }

        private function setAccessToken( $accessToken ){
            return update_option( self::$prefix . '_access_token', $accessToken );
        }

        private function getHashtag(){
            return get_option( self::$prefix . '_hashtag' );
        }

        private function setHashtag( $hashtag ){
            $hashtag = strtolower( $hashtag );
            $hashtag = preg_replace( '/[^a-z0-9äåö]/', '', $hashtag );
            return update_option( self::$prefix . '_hashtag', $hashtag );
        }

        private function getUsername(){
            return get_option( self::$prefix . '_username' );
        }

        private function setUsername( $username ){
            $username = strtolower( $username );
            $username = preg_replace( '/[^a-z0-9äåö]/', '', $username );

            $this->fetchUserID( $username );

            return update_option( self::$prefix . '_username', $username );
        }

        private function getUserId(){
            return get_option( self::$prefix . '_user_id' );
        }

        private function setUserId( $userId ){
            return update_option( self::$prefix . '_user_id', $userId );
        }

        private function getSubscriptionId(){
            return get_option( self::$prefix . '_subscription_id' );
        }

        private function setSubscriptionId( $subscriptionId ){
            return update_option( self::$prefix . '_subscription_id', $subscriptionId );
        }

        private function getCallbackUrl(){
            return plugin_dir_url( __FILE__ ) . 'instagram-callback.php';
        }
    }

    new SnmlInstagram();
