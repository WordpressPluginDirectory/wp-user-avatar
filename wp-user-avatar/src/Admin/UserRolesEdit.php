<?php

namespace ProfilePress\Core\Admin;

use WP_User;

class UserRolesEdit
{
    public function __construct()
    {
        add_action('show_user_profile', [$this, 'display_secondary_user_roles_field'], -1);
        add_action('edit_user_profile', [$this, 'display_secondary_user_roles_field'], -1);


        add_action('admin_footer', [$this, 'scripts'], 99);
        add_action('admin_head', [$this, 'styles']);

        // Must use `profile_update` to change role. Otherwise, WP will wipe it out.
        add_action('profile_update', [$this, 'update_roles'], 99, 2);
    }

    public function display_secondary_user_roles_field($user)
    {
        if (current_user_can('promote_users') && current_user_can('edit_user', $user->ID)) :

            $editable_roles = get_editable_roles();
            // Compare user role against currently editable roles.
            $user_roles = array_intersect(array_values($user->roles), array_keys($editable_roles));

            wp_nonce_field('ppress_new_user_roles', 'ppress_new_user_roles_nonce');
            ?>
            <h2><?php esc_html_e('Roles', 'wp-user-avatar'); ?></h2>
            <table class="form-table">
                <tbody>
                <tr>
                    <th><?php esc_html_e('Select User Roles', 'wp-user-avatar'); ?></th>
                    <td>
                        <div class="wp-tab-panel">
                            <ul>
                                <?php foreach ($editable_roles as $role => $details): ?>
                                    <li>
                                        <label>
                                            <input type="checkbox" name="ppress_user_roles[]" value="<?php echo esc_attr($role); ?>" <?php echo in_array($role, $user_roles) ? 'checked="checked"' : ''; ?>>
                                            <?php echo translate_user_role($details['name']); ?>
                                        </label>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
                </tbody>
            </table>
        <?php endif;
    }

    /**
     * @param int $user_id
     * @param WP_User $old_user_data
     *
     * @return void
     */
    public function update_roles($user_id, $old_user_data)
    {
        // Early return if user lacks permissions
        if ( ! current_user_can('promote_users') || ! current_user_can('edit_user', $user_id)) return;

        if (empty($_POST['ppress_new_user_roles_nonce']) || ! wp_verify_nonce($_POST['ppress_new_user_roles_nonce'], 'ppress_new_user_roles')) return;

        // Cache editable roles and extract just the role names for efficiency
        $editable_roles = array_keys(get_editable_roles());

        // Get current user roles
        $current_roles = (array)$old_user_data->roles;

        // Get and sanitize new roles, defaulting to empty array
        $new_roles = isset($_POST['ppress_user_roles']) ? array_map('sanitize_text_field', (array)$_POST['ppress_user_roles']) : [];

        // Filter new roles to only include editable ones
        $new_roles = array_intersect($new_roles, $editable_roles);

        // Calculate roles to add and remove
        $roles_to_add    = array_diff($new_roles, $current_roles);
        $roles_to_remove = array_intersect(array_diff($current_roles, $new_roles), $editable_roles);

        // Add new roles
        foreach ($roles_to_add as $role) {
            $old_user_data->add_role($role);
        }

        // Remove old roles
        foreach ($roles_to_remove as $role) {
            $old_user_data->remove_role($role);
        }

        do_action('ppress_user_roles_updated', $user_id, $roles_to_add, $roles_to_remove);
    }

    public function scripts()
    {
        if ( ! $this->is_profile_page()) return;
        ?>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelectorAll('.user-role-wrap').forEach(function (el) {
                    return el.remove();
                });
            });
        </script>
        <?php
    }

    public function styles()
    {
        if ( ! $this->is_profile_page()) return;
        ?>
        <style>.user-role-wrap {
                display: none !important;
            }</style>
        <?php
    }

    private function is_profile_page()
    {
        global $pagenow;

        return in_array($pagenow, ['profile.php', 'user-edit.php']);
    }

    public static function get_instance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new self();
        }

        return $instance;
    }
}