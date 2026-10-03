<?php

namespace ProfilePress\Core\ContentProtection\Frontend;

/**
 * Comments on restricted posts often quote or discuss the protected content, so they are hidden
 * from users who can't access the post. Restriction otherwise only applies via the_content.
 */
class CommentProtection
{
    public function __construct()
    {
        // covers comments_template(), the REST comments endpoint, widgets and other WP_Comment_Query consumers.
        add_filter('the_comments', [$this, 'filter_comments'], PHP_INT_MAX - 1);

        add_filter('comments_open', [$this, 'close_comments'], PHP_INT_MAX - 1, 2);
        add_filter('pings_open', [$this, 'close_comments'], PHP_INT_MAX - 1, 2);
        add_filter('get_comments_number', [$this, 'comments_number'], PHP_INT_MAX - 1, 2);

        // fallback for output paths that don't use WP_Comment_Query, e.g. comment feeds.
        add_filter('comment_text', [$this, 'comment_text'], PHP_INT_MAX - 1, 2);
        add_filter('comment_text_rss', [$this, 'comment_text'], PHP_INT_MAX - 1);
        add_filter('get_comment_excerpt', [$this, 'comment_excerpt'], PHP_INT_MAX - 1, 3);
    }

    protected function is_disabled()
    {
        // don't interfere with comment moderation screens.
        return (is_admin() && ! wp_doing_ajax()) || ! apply_filters('ppress_content_protection_restrict_comments', true);
    }

    /**
     * @param int $post_id
     *
     * @return bool
     */
    public function is_post_restricted($post_id)
    {
        $post_id = absint($post_id);

        if ($post_id < 1) return false;

        static $cache = [];

        if ( ! isset($cache[$post_id])) {

            $post = get_post($post_id);

            if ( ! $post) return $cache[$post_id] = false;

            // restriction checks evaluate the global post.
            $original_post   = $GLOBALS['post'] ?? null;
            $GLOBALS['post'] = $post;

            $cache[$post_id] = false !== PostContent::get_instance()->is_post_content_restricted();

            $GLOBALS['post'] = $original_post;
        }

        return $cache[$post_id];
    }

    /**
     * @param \WP_Comment[] $comments
     *
     * @return array
     */
    public function filter_comments($comments)
    {
        if ($this->is_disabled() || ! is_array($comments)) return $comments;

        return array_values(array_filter($comments, function ($comment) {
            return ! ($comment instanceof \WP_Comment) || ! $this->is_post_restricted($comment->comment_post_ID);
        }));
    }

    public function close_comments($open, $post_id = 0)
    {
        if ($this->is_disabled() || ! $open) return $open;

        return $this->is_post_restricted($post_id) ? false : $open;
    }

    public function comments_number($count, $post_id = 0)
    {
        if ($this->is_disabled()) return $count;

        return $this->is_post_restricted($post_id) ? 0 : $count;
    }

    public function comment_text($text, $comment = null)
    {
        if ($this->is_disabled()) return $text;

        if ( ! $comment instanceof \WP_Comment) $comment = get_comment();

        if ($comment instanceof \WP_Comment && $this->is_post_restricted($comment->comment_post_ID)) return '';

        return $text;
    }

    public function comment_excerpt($excerpt, $comment_id = 0, $comment = null)
    {
        if ($this->is_disabled()) return $excerpt;

        if ( ! $comment instanceof \WP_Comment) $comment = get_comment($comment_id);

        if ($comment instanceof \WP_Comment && $this->is_post_restricted($comment->comment_post_ID)) return '';

        return $excerpt;
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
