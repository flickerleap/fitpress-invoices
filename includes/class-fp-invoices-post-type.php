<?php

if ( ! defined( 'ABSPATH' ) ) :
    exit; // Exit if accessed directly
endif;

class FP_Invoices_Post_Type {

    /* We only want a single instance of this class. */
    private static $instance = null;

    /*
    * Creates or returns an instance of this class.
    *
    * @return  FP_Invoice A single instance of this class.
    */
    public static function get_instance( ) {
        if ( null == self::$instance ) {
            self::$instance = new self;
        }
        return self::$instance;
    } // end get_instance;

    /**
     * Hook in methods.
     */
    public function __construct(){

        add_action( 'init', array( $this, 'register_post_types' ), 5 );
        add_action( 'init', array( __CLASS__, 'register_post_status' ), 9 );

        if ( is_admin() ) {
            add_action( 'load-post.php',     array( $this, 'init_metabox' ) );
            add_action( 'load-post-new.php', array( $this, 'init_metabox' ) );
        }

        add_action( 'fitpress_after_membership_fields', array( $this, 'add_membership_fields' ) );
        add_filter( 'fitpress_before_membership_save', array( $this, 'save_membership_data' ) );

    }

    /**
     * Register core post types.
     */
    public static function register_post_types() {
        if ( post_type_exists('fp_invoice') ) {
            return;
        }

        do_action( 'action_register_post_type' );

        register_post_type( 'fp_invoice',
            array(
                'labels'             => array(
                    'name'                  => __( 'Invoices', 'fitpress-invoices-invoices' ),
                    'singular_name'         => __( 'Invoice', 'fitpress-invoices' ),
                    'menu_name'             => _x( 'Invoices', 'Admin menu name', 'fitpress-invoices' ),
                    'add_new'               => __( 'Add Invoice', 'fitpress-invoices' ),
                    'add_new_item'          => __( 'Add New Invoice', 'fitpress-invoices' ),
                    'edit'                  => __( 'Edit', 'fitpress-invoices' ),
                    'edit_item'             => __( 'Edit Invoice', 'fitpress-invoices' ),
                    'new_item'              => __( 'New Invoice', 'fitpress-invoices' ),
                    'view'                  => __( 'View Invoice', 'f§itpress-invoices' ),
                    'view_item'             => __( 'View Invoice', 'fitpress-invoices' ),
                    'search_items'          => __( 'Search Invoices', 'fitpress-invoices' ),
                    'not_found'             => __( 'No Invoices found', 'fitpress-invoices' ),
                    'not_found_in_trash'    => __( 'No Invoices found in trash', 'fitpress-invoices' ),
                    'parent'                => __( 'Parent Invoice', 'fitpress-invoices' ),
                    'featured_image'        => __( 'Invoice Image', 'fitpress-invoices' ),
                    'set_featured_image'    => __( 'Set invoice image', 'fitpress-invoices' ),
                    'remove_featured_image' => __( 'Remove invoice image', 'fitpress-invoices' ),
                    'use_featured_image'    => __( 'Use as invoice image', 'fitpress-invoices' ),
                ),
                'description'         => __( 'This is where you can add new memberships to your website.', 'fitpress-invoices' ),
                'public'              => false,
                'show_ui'             => true,
                'publicly_queryable'  => false,
                'exclude_from_search' => false,
                'hierarchical'        => false,
                'rewrite'             => false,
                'query_var'           => false,
                'has_archive'         => false,
                'show_in_nav_menus'   => true,
                'show_in_menu'        => 'fitpress',
                'supports'            => false
            )
        );

        register_post_type( 'fp_payment',
            array(
                'public'              => false,
                'show_ui'             => false,
                'publicly_queryable'  => false,
                'exclude_from_search' => false,
                'hierarchical'        => false,
                'rewrite'             => false,
                'query_var'           => false,
                'has_archive'         => false,
                'show_in_nav_menus'   => false,
                'supports'            => false
            )
        );
    }

    /**
     * Register our custom post statuses, used for order status.
     */
    public static function register_post_status() {
        register_post_status( 'fp-unpaid', array(
            'label'                     => _x( 'Unpaid', 'Invoice status', 'fitpress-invoice' ),
            'public'                    => false,
            'protected'                 => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Pending Payment <span class="count">(%s)</span>', 'Pending Payment <span class="count">(%s)</span>', 'fitpress-invoice' )
        ) );
        register_post_status( 'fp-paid', array(
            'label'                     => _x( 'Paid', 'Invoice status', 'fitpress-invoice' ),
            'public'                    => false,
            'exclude_from_search'       => false,
            'protected'                 => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Paid <span class="count">(%s)</span>', 'Paid <span class="count">(%s)</span>', 'fitpress-invoice' )
        ) );
    }

    /**
     * Meta box initialization.
     */
    public function init_metabox() {
        add_action( 'add_meta_boxes', array( $this, 'add_metabox'  )        );
        add_action( 'save_post',      array( $this, 'save_metabox' ), 10, 2 );
    }

    /**
     * Adds the meta box.
     */
    public function add_metabox() {
        add_meta_box(
            'invoice-data',
            __( 'Invoice Data', 'fitpress' ),
            array( $this, 'render_metabox' ),
            'fp_invoice',
            'advanced',
            'default'
        );

    }

    /**
     * Renders the meta box.
     */
    public function render_metabox( $post ) {
        // Add nonce for security and authentication.
        wp_nonce_field( FP_PLUGIN_FILE, 'membership_nonce' );

        $invoice_id = $post->ID;
        $line_items = get_post_meta( $invoice_id, 'fp_invoice_line_items', true );

        $invoice = array(
            'number' => get_post_meta( $invoice_id, 'fp_invoice_number', true ),
            'date' => get_post_meta( $invoice_id, 'fp_invoice_date', true ),
            'due_date' => get_post_meta( $invoice_id, 'fp_invoice_due_date',  true ),
        );
        $member_id = get_post_meta( $invoice_id, 'fp_user_id', true );
        $member = get_user_by( 'id', $member_id );

        ?>

        <p>
            <strong><?php echo $member->display_name;?></strong><br />
            <strong>Invoice Number:</strong> <?php echo $invoice['number']; ?><br />
            <strong>Date:</strong> <?php echo $invoice['date']; ?><br />
            <strong>Due Date:</strong><?php echo $invoice['due_date']; ?>
        </p>

        <table width="600">

            <thead>

                <tr>
                    <th style="width: 75%">Description</th>
                    <th>Price</th>
                </tr>

            </thead>

            <tbody>

            <?php $total = 0;?>

            <?php foreach( $line_items as $line_item ):?>

                <tr>

                    <td>
                        <?php echo $line_item['name'];?>
                    </td>
                    <td style="text-align: right;">
                        R <?php echo number_format( $line_item['price'], 2, '.', ' ');?>
                        <?php $total += $line_item['price'];?>
                    </td>
                </tr>

            <?php endforeach;?>

            </tbody>

            <tfooter>

                <tr>
                    <th style="text-align: right;padding: 5px;">VAT</th>
                    <th style="text-align: right;padding: 5px;">
                        R <?php
                        $VAT = ( ( $total / (1 + 14 / 100) ) - $total ) * -1;
                        echo number_format( ROUND($VAT, 2), 2, '.', ' ');
                        ?>
                    </th>
                </tr>

                <tr>
                    <th style="text-align: right;padding: 5px;">Total (incl. VAT)</th>
                    <th style="text-align: right;padding: 5px;">R <?php echo number_format( $total, 2, '.', ' ');?></th>
                </tr>

            </tfooter>

        </table>

        <?php

    }

    /**
     * Handles saving the meta box.
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @return null
     */
    public function save_metabox( $post_id, $post ) {
        // Add nonce for security and authentication.
        $nonce_name   = isset( $_POST['membership_nonce'] ) ? $_POST['membership_nonce'] : '';
        $nonce_action = FP_PLUGIN_FILE;

        // Check if nonce is set.
        if ( ! isset( $nonce_name ) ) {
            return;
        }

        // Check if nonce is valid.
        if ( ! wp_verify_nonce( $nonce_name, $nonce_action ) ) {
            return;
        }

        // Check if user has permissions to save data.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Check if not an autosave.
        if ( wp_is_post_autosave( $post_id ) ) {
            return;
        }

        // Check if not a revision.
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        return;

    }

    public function add_membership_fields( $membership_data ){

        ?>
        <p>
            <label for="price">Price</label>
            R <input name="price" type="text" value="<?php echo isset( $membership_data['price'] ) ? $membership_data['price'] : ''; ?>">
        </p>
        <p>
            <label for="term">Term</label>
            <select name="term">
                <option value="Once Off" <?php selected( isset( $membership_data['term'] ) ? $membership_data['term'] : '', 'Once Off');?>>Once Off</option>
                <option value="+1 month" <?php selected( isset( $membership_data['term'] ) ? $membership_data['term'] : '', '+1 month');?>>Monthly</option>
                <option value="+3 months" <?php selected( isset( $membership_data['term'] ) ? $membership_data['term'] : '', '+3 months');?>>Quarterly</option>
                <option value="+6 months" <?php selected( isset( $membership_data['term'] ) ? $membership_data['term'] : '', '+6 months');?>>Bi-annually</option>
                <option value="+1 year" <?php selected( isset( $membership_data['term'] ) ? $membership_data['term'] : '', '+1 year');?>>Annualy</option>
            </select>
        </p>
        <?php

    }

    public function save_membership_data( $membership_data ){

        if(isset($_POST["price"])){
            $membership_data['price'] = $_POST["price"];
        }

        if(isset($_POST["term"])){
            $membership_data['term'] = $_POST["term"];
        }

        return $membership_data;

    }

}

/**
 * Extension main function
 */
function __fp_invoices_post_type_main() {
    FP_Invoices_Post_Type::get_instance();
}

// Initialize plugin when plugins are loaded
add_action( 'fitpress_loaded', '__fp_invoices_post_type_main' );
