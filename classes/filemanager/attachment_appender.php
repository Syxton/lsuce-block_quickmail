<?php

namespace block_quickmail\filemanager;

use block_quickmail\persistents\message;
use block_quickmail\persistents\message_attachment;
use block_quickmail_string;
use moodle_url;
use html_writer;
use context_course;

class attachment_appender {

    public static $plugin_name = 'block_quickmail';

    public $message;
    public $body;
    public $course_context;
    public $message_attachments;

    public function __construct(message $message, $body) {
        $this->message = $message;
        $this->body = $body;
        $this->set_course_context();
        $this->set_message_attachments();
    }

    /**
     * Appends download links to the given message and body, if any
     * 
     * @param message  $message
     * @param string  $body
     */
    public static function add_download_links($message, $body)
    {
        $appender = new self($message, $body);

        // if there are no attachments for this message, return body as is
        if ( ! count($appender->message_attachments)) {
            return $appender->body;
        }

        $appender->add_download_all_links();

        // append a download link for each individual file
        $appender->add_individual_files();

        // run through the moodle cleanser thing
        $appender->body = file_rewrite_pluginfile_urls(
            $appender->body, 
            'pluginfile.php', 
            $appender->course_context->id, 
            'block_quickmail', 
            'attachments', 
            $appender->message->get('id')
        );
        
        return $appender->body;
    }

    /**
     * Appends "download all" links (both short and full) to the body
     */
    private function add_download_all_links()
    {
        if ($this->has_attachments()) {
            $this->body .= $this->hr();
            $this->body .= block_quickmail_string::get('moodle_attachments', $this->get_download_all_link());
            $this->body .= $this->br();
            $this->body .= $this->get_download_all_link(true);
        }
    }

    /**
     * Returns an HTML download link for a zip file containing all attachments
     * 
     * @param  bool  $as_url  whether or not to return with lang string text, or just plain
     * @return string
     */
    private function get_download_all_link($as_url = false)
    {
        $created_at = $this->message->get('timecreated');

        $filename = $created_at . '_attachments.zip';

        $url = $this->generate_url('/', $filename);

        return ! $as_url
            ? html_writer::link($url, block_quickmail_string::get('download_all'))
            : html_writer::link($url, $url);
    }

    /**
     * Appends download links for each attachment to the body
     */
    private function add_individual_files()
    {
        if ($this->has_attachments()) {
            $this->body .= $this->hr();
            $this->body .= block_quickmail_string::get('qm_contents');
            $this->body .= $this->br();

            // iterate through each attachment, adding a link and line break
            foreach ($this->message_attachments as $attachment) {
                $this->body .= html_writer::link($this->generate_url($attachment->get('path'), $attachment->get('filename')), $attachment->get_full_filepath());
                $this->body .= $this->br();
            }
        }
    }

    /**
     * Returns a URL pointing to a file with the given path and filename
     * 
     * @param  string  $path
     * @param  string  $filename
     * @return string            [description]
     */
    private function generate_url($path, $filename)
    {
        $url = moodle_url::make_pluginfile_url(
            $this->course_context->id, 
            'block_quickmail', 
            'attachments', 
            $this->message->get('id'), 
            $path, 
            $filename,
            true
        );

        return $url->out(false);
    }

    /**
     * Reports whether or not this appender's message has any attachments
     * 
     * @return bool
     */
    private function has_attachments()
    {
        return (bool) count($this->message_attachments);
    }

    private function hr()
    {
        return "\n<br/>-------\n<br/>";
    }

    private function br()
    {
        return "\n<br/>";
    }

    private function set_course_context()
    {
        $course = $this->message->get_course();

        $this->course_context = context_course::instance($course->id);
    }

    private function set_message_attachments()
    {
        $this->message_attachments = $this->message->get_message_attachments();
    }

}