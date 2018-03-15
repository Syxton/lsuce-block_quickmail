<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    block_quickmail
 * @copyright  2008-2017 Louisiana State University
 * @copyright  2008-2017 Adam Zapletal, Chad Mazilly, Philip Cali, Robert Russo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_quickmail\components;

use block_quickmail\components\component;
use block_quickmail_string;
use moodle_url;

class alternate_index_component extends component implements \renderable {

    public $alternate_emails;

    public $course_id;

    public function __construct($params = []) {
        parent::__construct($params);
        $this->alternate_emails = $this->get_param('alternate_emails');
        $this->course_id = $this->get_param('course_id');
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @return stdClass
     */
    public function export_for_template($output) {
        $data = (object)[];

        $data->courseId = $this->course_id;

        $data->tableHeadings = [
            get_string('email'),
            get_string('fullname'),
            block_quickmail_string::get('alternate_availability'),
            block_quickmail_string::get('status'),
            get_string('action')
        ];

        $data->tableRows = [];
        
        foreach ($this->alternate_emails as $alternate) {
            $data->tableRows[] = [
                'id' => $alternate->get('id'),
                'email' => $alternate->get('email'),
                'fullname' => $alternate->get_fullname(),
                'status' => $alternate->get_status(),
                'scope' => $alternate->get_scope(),
                'isValidated' => $alternate->get('is_validated'),
                'action' => $output->pix_icon('i/invalid', get_string('delete'))
            ];
        }

        $data->urlBack = $this->course_id 
            ? new moodle_url('/course/view.php', ['id' => $this->course_id])
            : new moodle_url('/my');

        $data->urlBackLabel = $this->course_id 
            ? block_quickmail_string::get('back_to_course')
            : 'Back to My page'; // TODO - make this a lang string
        
        return $data;
    }

}