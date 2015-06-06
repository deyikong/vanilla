<?php
/**
 * Draft model
 *
 * @copyright 2009-2015 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 * @package Vanilla
 * @since 2.0
 */

/**
 * Manages unpublished drafts of comments and discussions.
 */
class DraftModel extends VanillaModel {

    /**
     * Class constructor. Defines the related database table name.
     *
     * @since 2.0.0
     * @access public
     */
    public function __construct() {
        parent::__construct('Draft');
    }

    /**
     * Build base SQL query used by get methods.
     *
     * @since 2.0.0
     * @access public
     */
    public function DraftQuery() {
        $this->SQL
            ->Select('d.*')
            ->From('Draft d');
    }

    /**
     * Gets drafts matching the given criteria.
     *
     * @since 2.0.0
     * @access public
     *
     * @param int $UserID Unique ID of user that wrote the drafts.
     * @param int $Offset Number of results to skip.
     * @param int $Limit Max number of drafts to return.
     * @param int $DiscussionID Limits drafts returned to a single discussion.
     * @return object Gdn_DataSet SQL results.
     */
    public function Get($UserID, $Offset = '0', $Limit = '', $DiscussionID = '') {
        if (!is_numeric($Offset) || $Offset < 0) {
            $Offset = 0;
        }

        if (!is_numeric($Limit) || $Limit < 1) {
            $Limit = 100;
        }

        $this->DraftQuery();
        $this->SQL
            ->Select('d.Name, di.Name', 'coalesce', 'Name')
            ->Join('Discussion di', 'd.discussionID = di.DiscussionID', 'left')
            ->Where('d.InsertUserID', $UserID)
            ->OrderBy('d.DateInserted', 'desc')
            ->Limit($Limit, $Offset);

        if (is_numeric($DiscussionID) && $DiscussionID > 0) {
            $this->SQL->Where('d.DiscussionID', $DiscussionID);
        }

        return $this->SQL->Get();
    }

    /**
     * Gets data for a single draft.
     *
     * @since 2.0.0
     * @access public
     *
     * @param int $DraftID Unique ID of draft to get data for.
     * @return object SQL results.
     */
    public function GetID($DraftID) {
        $this->DraftQuery();
        return $this->SQL
            ->Where('d.DraftID', $DraftID)
            ->Get()
            ->FirstRow();
    }

    /**
     * Gets number of drafts a user has.
     *
     * @since 2.0.0
     * @access public
     *
     * @param int $UserID Unique ID of user to count drafts for.
     * @return int Total drafts.
     */
    public function GetCount($UserID) {
        return $this->SQL
            ->Select('DraftID', 'count', 'CountDrafts')
            ->From('Draft')
            ->Where('InsertUserID', $UserID)
            ->Get()
            ->FirstRow()
            ->CountDrafts;
    }

    /**
     * Insert or update a draft from form values.
     *
     * @since 2.0.0
     * @access public
     *
     * @param array $FormPostValues Form values sent from form model.
     * @return int Unique ID of draft.
     */
    public function Save($FormPostValues) {
        $Session = Gdn::Session();

        // Define the primary key in this model's table.
        $this->DefineSchema();

        // Add & apply any extra validation rules:
        $this->Validation->ApplyRule('Body', 'Required');
        $MaxCommentLength = Gdn::Config('Vanilla.Comment.MaxLength');
        if (is_numeric($MaxCommentLength) && $MaxCommentLength > 0) {
            $this->Validation->SetSchemaProperty('Body', 'Length', $MaxCommentLength);
            $this->Validation->ApplyRule('Body', 'Length');
        }

        // Get the DraftID from the form so we know if we are inserting or updating.
        $DraftID = ArrayValue('DraftID', $FormPostValues, '');
        $Insert = $DraftID == '' ? true : false;

        if (!$DraftID) {
            unset($FormPostValues['DraftID']);
        }

        // Remove the discussionid from the form value collection if it's empty
        if (array_key_exists('DiscussionID', $FormPostValues) && $FormPostValues['DiscussionID'] == '') {
            unset($FormPostValues['DiscussionID']);
        }

        if ($Insert) {
            // If no categoryid is defined, grab the first available.
            if (ArrayValue('CategoryID', $FormPostValues) === false) {
                $FormPostValues['CategoryID'] = $this->SQL->Get('Category', '', '', 1)->FirstRow()->CategoryID;
            }

        }
        // Add the update fields because this table's default sort is by DateUpdated (see $this->Get()).
        $this->AddInsertFields($FormPostValues);
        $this->AddUpdateFields($FormPostValues);

        // Remove checkboxes from the fields if they were unchecked
        if (ArrayValue('Announce', $FormPostValues, '') === false) {
            unset($FormPostValues['Announce']);
        }

        if (ArrayValue('Closed', $FormPostValues, '') === false) {
            unset($FormPostValues['Closed']);
        }

        if (ArrayValue('Sink', $FormPostValues, '') === false) {
            unset($FormPostValues['Sink']);
        }

        // Validate the form posted values
        if ($this->Validate($FormPostValues, $Insert)) {
            $Fields = $this->Validation->SchemaValidationFields(); // All fields on the form that relate to the schema
            $DraftID = intval(ArrayValue('DraftID', $Fields, 0));

            // If the post is new and it validates, make sure the user isn't spamming
            if ($DraftID > 0) {
                // Update the draft
                $Fields = RemoveKeyFromArray($Fields, 'DraftID'); // Remove the primary key from the fields for saving
                $this->SQL->Put($this->Name, $Fields, array($this->PrimaryKey => $DraftID));
            } else {
                // Insert the draft
                unset($Fields['DraftID']);
                $DraftID = $this->SQL->Insert($this->Name, $Fields);
                $this->UpdateUser($Session->UserID);
            }
        }

        return $DraftID;
    }

    /**
     * Deletes a specified draft.
     *
     * This is a hard delete that completely removes it.
     *
     * @since 2.0.0
     * @access public
     *
     * @param int $DraftID Unique ID of the draft to be deleted.
     * @return bool Always returns TRUE.
     */
    public function Delete($DraftID) {
        // Get some information about this draft
        $DraftUser = $this->SQL
            ->Select('InsertUserID')
            ->From('Draft')
            ->Where('DraftID', $DraftID)
            ->Get()
            ->FirstRow();

        $this->SQL->Delete('Draft', array('DraftID' => $DraftID));
        if (is_object($DraftUser)) {
            $this->UpdateUser($DraftUser->InsertUserID);
        }

        return true;
    }

    /**
     * Updates a user's draft count.
     *
     * @since 2.0.0
     * @access public
     *
     * @param int $UserID Unique ID of the user to be updated.
     */
    public function UpdateUser($UserID) {
        // Retrieve a draft count
        $CountDrafts = $this->GetCount($UserID);

        // Update CountDrafts column of user table fot this user
        Gdn::UserModel()->SetField($UserID, 'CountDrafts', $CountDrafts);
    }
}
