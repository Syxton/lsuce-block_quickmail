<?php

namespace block_quickmail\messenger;

use block_quickmail_config;
use block_quickmail_string;
use block_quickmail_emailer;
use block_quickmail\persistents\message;
use block_quickmail\persistents\alternate_email;
use block_quickmail\persistents\message_recipient;
use block_quickmail\persistents\message_draft_recipient;
use block_quickmail\persistents\message_additional_email;
use block_quickmail\validators\message_form_validator;
use block_quickmail\validators\save_draft_message_form_validator;
use block_quickmail\requests\compose_request;
use block_quickmail\requests\broadcast_request;
use block_quickmail\exceptions\validation_exception;
use block_quickmail\messenger\factories\course_recipient_send\recipient_send_factory;
use block_quickmail\filemanager\message_file_handler;
use block_quickmail\tasks\send_message_to_recipient_adhoc_task;
use core\task\manager as task_manager;
use block_quickmail\messenger\subject_prepender;
use block_quickmail\repos\user_repo;
use moodle_url;
use html_writer;

class messenger {

    public $message;

    public function __construct(message $message)
    {
        $this->message = $message;
    }

    /////////////////////////////////////////////////////////////
    ///
    ///  MESSAGE COMPOSITION METHODS
    /// 
    /////////////////////////////////////////////////////////////

    /**
     * Creates a "compose" (course-scoped) message from the given user within the given course using the given form data
     * 
     * Depending on the given form data, this message may be sent now or at some point in the future.
     * By default, the message delivery will be handled as individual adhoc tasks which are
     * picked up by a scheduled task.
     *
     * Optionally, a draft message may be passed which will use and update the draft information
     *
     * @param  object   $user            moodle user sending the message
     * @param  object   $course          course in which this message is being sent
     * @param  array    $form_data       message parameters which will be validated
     * @param  message  $draft_message   a draft message (optional, defaults to null)
     * @param  bool     $send_as_tasks   if false, the message will be sent immediately
     * @return message
     * @throws validation_exception
     * @throws critical_exception
     */
    public static function compose($user, $course, $form_data, $draft_message = null, $send_as_tasks = true)
    {
        // validate basic message form data
        self::validate_message_form_data($form_data, 'compose');

        // get transformed (valid) post data
        $transformed_data = compose_request::get_transformed_post_data($form_data);

        // get a message instance for this type, either from draft or freshly created
        $message = self::get_message_instance('compose', $user, $course, $transformed_data, $draft_message, false);
        
        // get only the resolved recipient user ids
        $recipient_user_ids = user_repo::get_unique_course_user_ids_from_selected_entities(
            $course, 
            $user, 
            $transformed_data->included_entity_ids, 
            $transformed_data->excluded_entity_ids
        );

        return self::send_message_to_recipients($message, $form_data, $recipient_user_ids, $transformed_data->additional_emails, $send_as_tasks);
    }

    /**
     * Creates an "broadcast" (admin, site-scoped) message from the given user using the given user filter and form data
     * 
     * Depending on the given form data, this message may be sent now or at some point in the future.
     * By default, the message delivery will be handled as individual adhoc tasks which are
     * picked up by a scheduled task.
     *
     * Optionally, a draft message may be passed which will use and update the draft information
     *
     * @param  object                                       $user                         moodle user sending the message
     * @param  object                                       $course                       the moodle "SITEID" course
     * @param  array                                        $form_data                    message parameters which will be validated
     * @param  block_quickmail_broadcast_recipient_filter   $broadcast_recipient_filter
     * @param  message                                      $draft_message                a draft message (optional, defaults to null)
     * @param  bool                                         $send_as_tasks                if false, the message will be sent immediately
     * @return message
     * @throws validation_exception
     * @throws critical_exception
     */
    public static function broadcast($user, $course, $form_data, $broadcast_recipient_filter, $draft_message = null, $send_as_tasks = true)
    {
        // validate basic message form data
        self::validate_message_form_data($form_data, 'broadcast');

        // be sure that we have at least one recipient from the given recipient filter results
        if ( ! $broadcast_recipient_filter->get_result_user_count()) {
            throw new validation_exception(block_quickmail_string::get('validation_exception_message'), block_quickmail_string::get('no_included_recipients_validation'));
        }

        // get transformed (valid) post data
        $transformed_data = broadcast_request::get_transformed_post_data($form_data);

        // get a message instance for this type, either from draft or freshly created
        $message = self::get_message_instance('broadcast', $user, $course, $transformed_data, $draft_message, false);

        // get the filtered recipient user ids
        $recipient_user_ids = $broadcast_recipient_filter->get_result_user_ids();

        return self::send_message_to_recipients($message, $form_data, $recipient_user_ids, $transformed_data->additional_emails, $send_as_tasks);
    }

    /**
     * Handles sending a given message to the given recipient user ids
     *
     * This will clear any draft-related data for the message, and sync it's recipients/additional emails
     *
     * @param  message  $message              message object instance being sent
     * @param  array    $form_data            posted moodle form data (used for file attachment purposes)
     * @param  array    $recipient_user_ids   moodle user ids to receive the message
     * @param  array    $additional_emails    array of additional email addresses to send to, optional, defaults to empty
     * @param  bool     $send_as_tasks        if false, the message will be sent immediately
     * @return message
     * @throws critical_exception
     */
    private static function send_message_to_recipients($message, $form_data, $recipient_user_ids = [], $additional_emails, $send_as_tasks = true)
    {
        // handle saving and syncing of any uploaded file attachments
        message_file_handler::handle_posted_attachments($message, $form_data, 'attachments');
        
        // clear any draft recipients for this message, unnecessary at this point
        message_draft_recipient::clear_all_for_message($message);

        // clear any existing recipients, and add those that have been recently submitted
        $message->sync_recipients($recipient_user_ids);

        // clear any existing additional emails, and add those that have been recently submitted
        $message->sync_additional_emails($additional_emails);
        
        // if not sending as a task, and scheduled for delivery later, send now
        // the ability to do this is not allowed for the end user, but available for testing
        // TODO: make task-based sending configurable (ie: do not allow scheduled sends)
        if ( ! $send_as_tasks && ! $message->get_to_send_in_future()) {
            self::deliver($message, $send_as_tasks);
        }

        return $message;
    }

    /////////////////////////////////////////////////////////////
    ///
    ///  MESSAGE DRAFTING METHODS
    /// 
    /////////////////////////////////////////////////////////////

    /**
     * Creates a draft "compose" (course-scoped) message from the given user within the given course using the given form data
     * 
     * Optionally, a draft message may be passed which will be updated rather than created anew
     *
     * @param  object   $user            moodle user sending the message
     * @param  object   $course          course in which this message is being sent
     * @param  array    $form_data       message parameters which will be validated
     * @param  message  $draft_message   a draft message (optional, defaults to null)
     * @return message
     * @throws validation_exception
     * @throws critical_exception
     */
    public static function save_compose_draft($user, $course, $form_data, $draft_message = null)
    {
        self::validate_draft_form_data($form_data, 'compose');

        // get transformed (valid) post data
        $transformed_data = compose_request::get_transformed_post_data($form_data);

        // get a message instance for this type, either from draft or freshly created
        $message = self::get_message_instance('compose', $user, $course, $transformed_data, $draft_message, true);

        // @TODO: handle posted file attachments (moodle)

        // clear any existing draft recipients, and add those that have been recently submitted
        $message->sync_compose_draft_recipients($transformed_data->included_entity_ids, $transformed_data->excluded_entity_ids);
        
        // get only the resolved recipient user ids
        $recipient_user_ids = user_repo::get_unique_course_user_ids_from_selected_entities(
            $course, 
            $user, 
            $transformed_data->included_entity_ids, 
            $transformed_data->excluded_entity_ids
        );

        // clear any existing recipients, and add those that have been recently submitted
        $message->sync_recipients($recipient_user_ids);

        // clear any existing additional emails, and add those that have been recently submitted
        $message->sync_additional_emails($transformed_data->additional_emails);
        
        // @TODO: sync posted attachments to message record
        
        return $message;
    }
    
    /**
     * Creates a draft "broadcast" (system-scoped) message from the given user within the given course using the given form data
     * 
     * Optionally, a draft message may be passed which will be updated rather than created anew
     *
     * @param  object                                       $user            moodle user sending the message
     * @param  object                                       $course          course in which this message is being sent
     * @param  array                                        $form_data       message parameters which will be validated
     * @param  block_quickmail_broadcast_recipient_filter   $broadcast_recipient_filter
     * @param  message                                      $draft_message   a draft message (optional, defaults to null)
     * @return message
     * @throws validation_exception
     * @throws critical_exception
     */
    public static function save_broadcast_draft($user, $course, $form_data, $broadcast_recipient_filter, $draft_message = null)
    {
        self::validate_draft_form_data($form_data, 'broadcast');

        // get transformed (valid) post data
        $transformed_data = broadcast_request::get_transformed_post_data($form_data);

        // get a message instance for this type, either from draft or freshly created
        $message = self::get_message_instance('broadcast', $user, $course, $transformed_data, $draft_message, true);

        // @TODO: handle posted file attachments (moodle)
        
        // clear any existing draft recipient filters, and add this recently submitted value
        $message->sync_broadcast_draft_recipients($broadcast_recipient_filter->get_filter_value());
        
        // get the filtered recipient user ids
        $recipient_user_ids = $broadcast_recipient_filter->get_result_user_ids();

        // clear any existing recipients, and add those that have been recently submitted
        $message->sync_recipients($recipient_user_ids);

        // clear any existing additional emails, and add those that have been recently submitted
        $message->sync_additional_emails($transformed_data->additional_emails);
        
        // @TODO: sync posted attachments to message record
        
        return $message;
    }

    public static function duplicate_draft($draft_id, $user)
    {
        // get the draft to be duplicated
        if ( ! $original_draft = new message($draft_id)) {
            throw new validation_exception(block_quickmail_string::get('could_not_duplicate'));
        }

        // make sure it's a draft
        if ( ! $original_draft->is_message_draft()) {
            throw new validation_exception(block_quickmail_string::get('must_be_draft_to_duplicate'));
        }

        // check that the draft belongs to the given user id
        if ($original_draft->get('user_id') !== $user->id) {
            throw new validation_exception(block_quickmail_string::get('must_be_owner_to_duplicate'));
        }

        // create a new draft message from the original's data
        $new_draft = message::create_new([
            'course_id' => $original_draft->get('course_id'),
            'user_id' => $original_draft->get('user_id'),
            'message_type' => $original_draft->get('message_type'),
            'alternate_email_id' => $original_draft->get('alternate_email_id'),
            'signature_id' => $original_draft->get('signature_id'),
            'subject' => $original_draft->get('subject'),
            'body' => $original_draft->get('body'),
            'editor_format' => $original_draft->get('editor_format'),
            'is_draft' => 1,
            'send_receipt' => $original_draft->get('send_receipt'),
            'no_reply' => $original_draft->get('no_reply'),
            'usermodified' => $user->id
        ]);

        // duplicate the message recipients
        foreach ($original_draft->get_message_recipients() as $recipient) {
            message_recipient::create_new([
                'message_id' => $new_draft->get('id'),
                'user_id' => $recipient->get('user_id'),
            ]);
        }

        // duplicate the message draft recipients
        foreach ($original_draft->get_message_draft_recipients() as $recipient) {
            message_draft_recipient::create_new([
                'message_id' => $new_draft->get('id'),
                'type' => $recipient->get('type'),
                'recipient_type' => $recipient->get('recipient_type'),
                'recipient_id' => $recipient->get('recipient_id'),
                'recipient_filter' => $recipient->get('recipient_filter'),
            ]);
        }

        // duplicate the message additional emails
        foreach ($original_draft->get_additional_emails() as $additional_email) {
            message_additional_email::create_new([
                'message_id' => $new_draft->get('id'),
                'email' => $additional_email->get('email'),
            ]);
        }

        return $new_draft;
    }

    /**
     * Validates message form data for a given message "type" (compose/broadcast)
     * 
     * @param  array   $form_data   message parameters which will be validated
     * @param  string  $type        compose|broadcast
     * @return void
     * @throws validation_exception
     */
    private static function validate_message_form_data($form_data, $type)
    {
        $extra_params = $type == 'broadcast'
            ? ['is_broadcast_message' => true]
            : [];

        // validate form data
        $validator = new message_form_validator($form_data, $extra_params);
        $validator->validate();

        // if errors, throw exception
        if ($validator->has_errors()) {
            throw new validation_exception(block_quickmail_string::get('validation_exception_message'), $validator->errors);
        }
    }

    /**
     * Validates draft message form data for a given message "type" (compose/broadcast)
     * 
     * @param  array   $form_data   message parameters which will be validated
     * @param  string  $type        compose|broadcast
     * @return void
     * @throws validation_exception
     */
    private static function validate_draft_form_data($form_data, $type)
    {
        $extra_params = $type == 'broadcast'
            ? ['is_broadcast_message' => true]
            : [];

        // validate form data
        $validator = new save_draft_message_form_validator($form_data, $extra_params);
        $validator->validate();

        // if errors, throw exception
        if ($validator->has_errors()) {
            throw new validation_exception(block_quickmail_string::get('validation_exception_message'), $validator->errors);
        }
    }

    /**
     * Returns a message object instance of the given type from the given params
     *
     * If a draft message is passed, the draft message will be updated to "non-draft" status and returned
     * otherwise, a new message instance will be created with the given user, course, and posted data
     * 
     * @param  string  $type               compose|broadcast
     * @param  object  $user               auth user creating the message
     * @param  object  $course             scoped course for this message
     * @param  object  $transformed_data   transformed posted form data
     * @param  message $draft_message
     * @param  bool    $is_draft           whether or not this instance is being resolved for purposes of saving as draft
     * @return message
     */
    private static function get_message_instance($type, $user, $course, $transformed_data, $draft_message = null, $is_draft = false)
    {
        // if draft message was passed
        if ( ! empty($draft_message)) {
            // if draft message was already sent (shouldn't happen)
            if ($draft_message->is_sent_message()) {
                throw new validation_exception(block_quickmail_string::get('critical_error'));
            }

            // update draft message, and remove draft status
            $message = $draft_message->update_draft($transformed_data, $is_draft);
        } else {
            // create new message
            $message = message::create_type($type, $user, $course, $transformed_data, $is_draft);
        }

        return $message;
    }

    /////////////////////////////////////////////////////////////
    ///
    ///  MESSAGE SENDING METHODS
    /// 
    /////////////////////////////////////////////////////////////

    /**
     * Instantiates a messenger and performs the delivery of the given message to all of its recipients
     * By default, the message to recipient transactions will be queued to send as adhoc tasks
     * 
     * @param  message  $message     message to be sent
     * @param  bool     $queue_send  if false, the message will be sent immediately
     * @return bool
     */
    public static function deliver(message $message, $queue_send = true)
    {
        // is message is currently being sent, bail out
        if ($message->is_being_sent()) {
            return false;
        }

        $messenger = new self($message);

        return $messenger->send($queue_send);
    }
    
    /////////////////////////////////////////////////////////////
    ///
    ///  MESSENGER INSTANCE METHODS
    /// 
    /////////////////////////////////////////////////////////////

    /**
     * Sends the message to all of its recipients
     * 
     * @param  bool     $queue_send  if true, will send each delivery as an adhoc task, otherwise will send synchronously right away
     * @return bool
     */
    public function send($queue_send = true)
    {
        // if sending synchronously, handle pre-send actions
        if ( ! $queue_send) {
            $this->handle_message_pre_send();
        }

        // iterate through all message recipients
        foreach($this->message->get_message_recipients() as $recipient) {
            // if any exceptions are thrown, gracefully move to the next recipient
            try {
                // if sending synchronously, send to recipient now
                if ( ! $queue_send) {
                    // send now
                    $this->send_to_recipient($recipient);
                
                // otherwise, queue a task to handle sending to the recipient
                } else {
                    $task = new send_message_to_recipient_adhoc_task();

                    $task->set_custom_data([
                        'message_id' => $this->message->get('id'),
                        'recipient_id' => $recipient->get('id'),
                    ]);

                    task_manager::queue_adhoc_task($task);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // if sending synchronously, handle post-send actions
        if ( ! $queue_send) {
            $this->handle_message_post_send();
        }

        return true;
    }

    /**
     * Sends the message to the given recipient
     * 
     * @param  message_recipient  $recipient   message recipient to recieve the message
     * @return bool
     */
    public function send_to_recipient($recipient)
    {
        // instantiate recipient_send_factory
        $recipient_send_factory = recipient_send_factory::make($this->message, $recipient);

        // send recipient_send_factory
        $recipient_send_factory->send();

        return true;
    }

    /**
     * Performs pre-send actions
     * 
     * @return void
     */
    public function handle_message_pre_send()
    {
        $this->message->set('is_sending', 1);
        $this->message->update();
        $this->message->read(); // necessary?
    }

    /**
     * Performs post-send actions
     * 
     * @return void
     */
    public function handle_message_post_send()
    {
        // send to any additional emails (if any)
        $this->send_message_additional_emails();

        // send receipt message (if applicable)
        if ($this->message->should_send_receipt()) {
            $this->send_message_receipt();
        }
        
        // update message as having been sent
        $this->message->set('is_sending', 0);
        $this->message->set('sent_at', time());
        $this->message->update();
        $this->message->read(); // necessary?
    }

    /**
     * Sends an email to each of this message's additional emails (if any)
     * 
     * @return void
     */
    private function send_message_additional_emails()
    {
        $fromuser = $this->message->get_user();

        $subject = subject_prepender::format_course_subject(
            $this->message->get_course(), 
            $this->message->get('subject')
        );

        $body = $this->message->get('body'); // @TODO - find some way to clean out any custom data fields for this fake user (??)
        
        foreach($this->message->get_additional_emails() as $additional_email) {
            if ( ! $additional_email->has_been_sent_to()) {
                // instantiate an emailer
                $emailer = new block_quickmail_emailer($fromuser, $subject, $body);
                $emailer->to_email($additional_email->get('email'));

                // determine reply to parameters based off of message settings
                if ( ! (bool) $this->message->get('no_reply')) {
                    // if the message has an alternate email, reply to that
                    if ($alternate_email = alternate_email::find_or_null($this->message->get('alternate_email_id'))) {
                        $replyto_email = $alternate_email->get('email');
                        $replyto_name = $alternate_email->get_fullname();
                    
                    // otherwise, reply to sending user
                    } else {
                        $replyto_email = $fromuser->email;
                        $replyto_name = fullname($fromuser);
                    }

                    $emailer->reply_to($replyto_email, $replyto_name);
                }

                // attempt to send the email
                if ($emailer->send()) {
                    $additional_email->mark_as_sent();
                }
            }
        }
    }

    /**
     * Sends an email receipt to the sending user, if necessary
     * 
     * @return void
     */
    private function send_message_receipt()
    {
        $fromuser = $this->message->get_user();

        $subject = subject_prepender::format_for_receipt_subject(
            $this->message->get('subject')
        );

        $body = $this->get_receipt_message_body();
        
        // instantiate an emailer
        $emailer = new block_quickmail_emailer($fromuser, $subject, $body);
        $emailer->to_email($fromuser->email);

        // determine reply to parameters based off of message settings
        if ( ! (bool) $this->message->get('no_reply')) {
            $emailer->reply_to($fromuser->email, fullname($fromuser));
        }

        // attempt to send the email
        $emailer->send();

        // flag message as having sent the receipt message
        $this->message->mark_receipt_as_sent();
    }

    /**
     * Returns a body of text content for this message's send receipt
     * 
     * @return string
     */
    private function get_receipt_message_body()
    {
        $data = (object) [];

        $data->subject = $this->message->get('subject');
        // TODO - format this course name based off of preference?
        $data->course_name = $this->message->get_course()->fullname;
        $data->recipient_count = $this->message->cached_recipient_count();
        $data->additional_email_count = $this->message->cached_additional_email_count();
        $data->attachment_count = $this->message->cached_attachment_count();
        $data->sent_message_link = html_writer::link(new moodle_url('/blocks/quickmail/sent.php', ['courseid' => $this->message->get_course()->id]), block_quickmail_string::get('here'));

        return block_quickmail_string::get('receipt_email_body', $data);
    }

}