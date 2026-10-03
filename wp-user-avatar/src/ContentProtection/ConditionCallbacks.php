<?php

namespace ProfilePress\Core\ContentProtection;


/**
 * Callbacks that decide whether a content protection rule's condition matches.
 *
 * Callbacks are invoked by Checker::content_match() with ($condition_id, $rule_saved_value, $is_redirect)
 * in two contexts:
 *
 * 1. Redirect ($is_redirect = true), from Frontend\Redirect on template_redirect. "Does the page the visitor
 *    is viewing match?" Main-query conditional tags (is_front_page(), is_page(), is_page_template()...) are
 *    correct here.
 *
 * 2. Content filtering ($is_redirect = false), from Frontend\PostContent on the_content. "Does the post whose
 *    content is being printed match?" the_content runs for any post printed anywhere: REST API responses
 *    (/wp-json/wp/v2/pages/<id>), RSS feeds, search result excerpts, Query Loop blocks and other secondary
 *    loops. In those requests the main query isn't the protected post, so main-query conditional tags return
 *    false, the condition doesn't match and the protected content leaks. Conditions must check the global
 *    $post (the post being filtered) instead.
 *
 * When adding a condition, handle both contexts. See front_page(), blog_page() and the 'template' case in
 * post_type() for examples.
 */
class ConditionCallbacks
{
    /**
     * Checks if this is one of the selected post_type items.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     * @param bool $is_redirect
     *
     * @return bool
     */
    public static function post_type($condition_id, $rule_saved_value, $is_redirect = false)
    {
        global $post;

        $post_id = isset($post->ID) && absint($post->ID) > 0 ? $post->ID : get_queried_object_id();

        $target = explode('_', $condition_id);

        // Modifier should be the last key.
        $modifier = array_pop($target);

        // Post type is the remaining keys combined.
        $post_type = implode('_', $target);

        $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];

        switch ($modifier) {
            case 'index':

                if (is_post_type_archive($post_type)) return true;
                break;

            case 'all':

                if (self::_is_post_type($post_type)) {

                    // do not redirect home and blog page. if there is a need, add the homepage and blog page OR rule.
                    if (true === $is_redirect && ( ! is_singular($post_type) || is_front_page() || is_home())) return false;

                    return true;
                }
                break;

            case 'selected':

                if (self::_is_post_type($post_type) && in_array($post_id, wp_parse_id_list($selected))) {

                    if (true === $is_redirect && ! is_singular($post_type)) return false;

                    return true;
                }
                break;

            case 'children':

                if ( ! is_post_type_hierarchical($post_type) || ! self::_is_post_type($post_type)) return false;

                $selected = wp_parse_id_list($selected);

                foreach ($selected as $id) {

                    if ($post->post_parent == $id) {

                        if (true === $is_redirect && ! is_singular($post_type)) return false;

                        return true;
                    }
                }
                break;

            case 'ancestors':

                if ( ! is_post_type_hierarchical($post_type) || ! self::_is_post_type($post_type)) return false;

                $selected = wp_parse_id_list($selected);

                foreach ($selected as $id) {

                    $ancestors = get_post_ancestors($id);

                    if (in_array($post_id, $ancestors)) {

                        if (true === $is_redirect && ! is_singular($post_type)) return false;

                        return true;
                    }
                }
                break;

            case 'template':

                if (true === $is_redirect) {
                    if (is_page() && is_page_template($selected)) return true;
                    break;
                }

                // Content filtering: check the template of the post being filtered, not the main query.
                // This used to be `is_page() && is_page_template($selected)` for both contexts, but in REST,
                // feeds and secondary loops is_page() is false and is_page_template() reads the queried object,
                // so pages protected by template were served unprotected through /wp-json/wp/v2/pages/<id>.
                // get_page_template_slug() returns '' for the default template, hence the 'default' check.
                if (self::_is_post_type('page')) {

                    $selected      = (array)$selected;
                    $page_template = get_page_template_slug($post_id);

                    if (in_array($page_template, $selected, true) || (empty($page_template) && in_array('default', $selected, true))) {
                        return true;
                    }
                }
                break;
        }

        return false;
    }

    /**
     * Checks if this is one of the selected taxonomy term.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     *
     * @return bool
     */
    public static function taxonomy($condition_id, $rule_saved_value)
    {
        $target = explode('_', $condition_id);

        // Remove the tax_ prefix.
        array_shift($target);

        // Assign the last key as the modifier _all, _selected
        $modifier = array_pop($target);

        // Whatever is left is the taxonomy.
        $taxonomy = implode('_', $target);

        if ($taxonomy == 'category') {
            return self::_category($condition_id, $rule_saved_value);
        }

        if ($taxonomy == 'post_tag') {
            return self::_post_tag($condition_id, $rule_saved_value);
        }

        switch ($modifier) {
            case 'all':
                if (is_tax($taxonomy)) {
                    return true;
                }
                break;

            case 'selected':
                $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];

                if (is_tax($taxonomy, wp_parse_id_list($selected))) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Checks if the post_type has the selected categories.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     * @param bool $is_redirect
     *
     * @return bool
     */
    public static function post_type_tax($condition_id, $rule_saved_value, $is_redirect = false)
    {
        $target = explode('_w_', $condition_id);

        // First key is the post type.
        $post_type = array_shift($target);

        // Last Key is the taxonomy
        $taxonomy = array_pop($target);

        if ($taxonomy == 'category') {

            if (true === $is_redirect && ! is_singular($post_type)) return false;

            return self::_post_type_category($condition_id, $rule_saved_value);
        }

        if ($taxonomy == 'post_tag') {

            if (true === $is_redirect && ! is_singular($post_type)) return false;

            return self::_post_type_tag($condition_id, $rule_saved_value);
        }

        $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];

        if (self::_is_post_type($post_type) && has_term(wp_parse_id_list($selected), $taxonomy)) {

            if (true === $is_redirect && ! is_singular($post_type)) return false;

            return true;
        }

        return false;
    }


    /**
     * Checks if this is one of the selected categories.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     *
     * @return bool
     */
    public static function _category($condition_id, $rule_saved_value)
    {
        $target = explode('_', $condition_id);

        // Assign the last key as the modifier _all, _selected
        $modifier = array_pop($target);

        switch ($modifier) {
            case 'all':
                if (is_category()) return true;
                break;

            case 'selected':
                $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];
                if (is_category(wp_parse_id_list($selected))) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Checks if this is one of the selected tags.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     *
     * @return bool
     */
    public static function _post_tag($condition_id, $rule_saved_value)
    {
        $target = explode('_', $condition_id);

        $modifier = array_pop($target);

        switch ($modifier) {
            case 'all':
                if (is_tag()) {
                    return true;
                }
                break;

            case 'selected':
                $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];
                if (is_tag(wp_parse_id_list($selected))) {
                    return true;
                }
                break;
        }

        return false;
    }

    /**
     * Checks if the post_type has the selected categories.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     *
     * @return bool
     */
    public static function _post_type_category($condition_id, $rule_saved_value)
    {
        $target = explode('_w_', $condition_id);

        // First key is the post type.
        $post_type = array_shift($target);

        $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];

        if (self::_is_post_type($post_type) && has_category(wp_parse_id_list($selected))) {
            return true;
        }

        return false;
    }

    /**
     * Checks is a post_type has the selected tags.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     *
     * @return bool
     */
    public static function _post_type_tag($condition_id, $rule_saved_value)
    {
        $target = explode('_w_', $condition_id);

        // First key is the post type.
        $post_type = array_shift($target);

        $selected = ! empty($rule_saved_value) ? $rule_saved_value : [];
        if (self::_is_post_type($post_type) && has_tag(wp_parse_id_list($selected))) {
            return true;
        }

        return false;
    }

    /**
     * @param string $post_type
     *
     * @return bool
     */
    /**
     * "Home or Front Page" condition.
     *
     * This was registered as the bare `is_front_page` conditional tag. That only answers "is the main query the
     * front page?", so in a REST request (/wp-json/wp/v2/pages/<front page id>) or on a search results page
     * (front page excerpt) it returned false, the rule didn't match and the protected front page content was
     * served to anyone.
     *
     * - is_front_page() true: matches, as before, in both contexts.
     * - Redirect context: stays query-based. Redirecting should only happen when the visitor is on the home page.
     * - Content filtering: also matches when the post being filtered is the page set in Settings > Reading >
     *   Homepage (page_on_front), wherever that content is printed.
     * - When the homepage shows "Your latest posts" there is no front page post, so only is_front_page() applies.
     *
     * Don't switch the registration in ContentConditions back to 'is_front_page'.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     * @param bool $is_redirect
     *
     * @return bool
     */
    public static function front_page($condition_id = '', $rule_saved_value = '', $is_redirect = false)
    {
        if (is_front_page()) return true;

        if (true === $is_redirect || 'page' !== get_option('show_on_front')) return false;

        return self::_is_page_option_post('page_on_front');
    }

    /**
     * "Blog or Posts Page" condition.
     *
     * Same problem and fix as front_page(). The bare `is_home` conditional tag only matched when the main query
     * was the posts page, so the posts page's own content leaked through REST and search. In the content filtering
     * context this also matches the page set in Settings > Reading > Posts page (page_for_posts).
     *
     * Don't switch the registration in ContentConditions back to 'is_home'.
     *
     * @param string $condition_id
     * @param mixed $rule_saved_value
     * @param bool $is_redirect
     *
     * @return bool
     */
    public static function blog_page($condition_id = '', $rule_saved_value = '', $is_redirect = false)
    {
        if (is_home()) return true;

        if (true === $is_redirect) return false;

        return self::_is_page_option_post('page_for_posts');
    }

    /**
     * Whether the post being filtered (global $post, set by the loop, REST controller or feed) is the page set
     * in the given reading option.
     *
     * @param string $option page_on_front or page_for_posts
     *
     * @return bool
     */
    protected static function _is_page_option_post($option)
    {
        global $post;

        $page_id = absint(get_option($option));

        return $page_id > 0 && is_object($post) && absint($post->ID) === $page_id;
    }

    public static function _is_post_type($post_type)
    {
        global $post;

        return is_object($post) && $post->post_type == $post_type;
    }
}