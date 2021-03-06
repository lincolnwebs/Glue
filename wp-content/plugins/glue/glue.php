<?php
/*
Plugin Name: Glue
Plugin URI: http://github.com/lincolnwebs/glue
Description: Glues WordPress to your Vanilla Forum.
Author: Lincoln Russell <lincoln@icrontic.com>
Version: 1.3
Author URI: http://lincolnwebs.com
*/

/**
 * WordPress Glue plugin.
 *
 * @package Glue
 * @copyright 2011 Matt Lincoln Russell <lincolnwebs@gmail.com>
 */

// Pass GET params around Vanilla
if (isset($_GET)) 
   $GetHolder = $_GET;

// Include Garden framework
require_once(dirname(__FILE__).'/config.php');
require_once(dirname(__FILE__).'/vanilla.php');

// Pass GET params around Vanilla
if (isset($GetHolder)) {
   $_GET = $GetHolder;
   unset($GetHolder);
}

// Hooks you can disable.
if (C('Glue.Discussions.Add', TRUE)) {
   add_action('publish_post', 'glue_add_discussion');
}
if (C('Glue.Comments.Add', TRUE)) {
   add_action('comment_post', 'glue_add_comment');
}

// Hooks you need to invoke in your theme.
add_action('vanilla_comments', 'glue_get_comments');
add_action('vanilla_postinfo', 'glue_get_postinfo');

// Identity integration points.
add_action('wp_logout', 'glue_logout');
add_action('admin_menu', 'glue_block_profile');

/**
 * Authenticate users from Vanilla cookie instead.
 */
function wp_validate_auth_cookie() {
   // Get & authenticate Vanilla cookie
   $auth_object = new Gdn_CookieIdentity();
   return $auth_object->GetIdentity();
}

/**
 * Create Vanilla discussion for each new WordPress post.
 *
 * @param int $postid
 */
function glue_add_discussion($postid) {
   // Verify discussion has not been created
   $discussionid = get_post_meta($postid, 'discussionid', true);
   if ($discussionid > 0)
     return;
   
   // Get post info
   $the_post = get_post($postid);
   list($category) = get_the_category($postid);
   
   // CategoryID
   $default_cat = C('Glue.Category.Default', 1);
   $categoryid = C('Glue.Category.'.$category->name, $default_cat);

   // Build discussion data
   $userid = intval($the_post->post_author);
   $title = $the_post->post_title;
   $link = str_replace('%postname%', $the_post->post_name, get_permalink($the_post->ID, true));
   $body = '<a href="'.$link.'">'.$title.'</a>';
   $DiscussionData = array(
      'CategoryID' => $categoryid, 
      'InsertUserID' => $userid, 
      'Name' => $title, 
      'Body' => $body, 
      'Format' => 'Html', 
      'DateInserted' => $the_post->post_date
   );
   
   // Create discussion
   $DiscussionModel = new DiscussionModel();
   $DiscussionModel->SpamCheck = FALSE;
   $DiscussionModel->Glue = TRUE; // Set flag to intercept DateLastComment later
   $DiscussionID = $DiscussionModel->Save($DiscussionData);

   // Update Post
   update_post_meta($postid, 'discussionid', $DiscussionID);
}

/**
 * Copy new WordPress comment to Vanilla Comment.
 *
 * @param int $commentid
 */
function glue_add_comment($commentid) {   
   // Get comment info
   global $wpdb;
   $comment = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."comments WHERE comment_ID = '$commentid'");
   
   // Ignore spam
   if ($comment->comment_approved === 'spam')
      return;
   
   // Check for closed discussions
   $DiscussionID = get_post_meta($comment->comment_post_ID, 'discussionid', true);
   $DiscussionModel = new DiscussionModel();
   $Discussion = $DiscussionModel->GetID($DiscussionID);
   if ($Discussion->Closed == 1)
      return;

   // Check for guest comment timeout
   $DaysSince = (strtotime($Discussion->DateInserted) - now()) / 3600 / 24;
   if ($DaysSince > C('Glue.Comments.DaysAllowed'))
      return;
      
   // Create Comment
   $CommentModel = new CommentModel();
   $CommentData = array(
     'DiscussionID' => $DiscussionID, 
     'InsertUserID' => $comment->user_id, 
     'Body' => $comment->comment_content, 
     'Format' => C('Garden.InputFormatter'), 
     'DateInserted' => $comment->comment_date, 
     'InsertIPAddress' => $comment->comment_author_IP, 
     'GuestName' => $comment->comment_author, 
     'GuestEmail' => $comment->comment_author_email, 
     'GuestUrl' => $comment->comment_author_url
   );
   
   //$CommentModel->SpamCheck = FALSE; // Srsly wtf was I doing here
   $CommentID = $CommentModel->Save($CommentData);
   if ($CommentID) 
      $CommentModel->Save2($CommentID, TRUE, TRUE, TRUE);
}

/**
 * Get comments to display in WordPress.
 *
 * @todo Add a limit or pagination.
 * @param int $postid
 */
function glue_get_comments($postid) {
   global $vanilla_comments, $discussionid, $discussion_closed;
   $discussion_closed = FALSE;
   
   if (!is_numeric($postid))
      return FALSE;
   
   $discussionid = get_post_meta($postid, 'discussionid', true);
   if (!$discussionid) {
      $vanilla_comments = array();
      return FALSE;
   }
   
   // Get comments
   $CommentModel = new CommentModel();
   $CommentData = $CommentModel->Get($discussionid, 100);
   $vanilla_comments = $CommentData->Result(DATASET_TYPE_ARRAY);
   
   // Set user discussion data
   $DiscussionModel = new DiscussionModel();
   $Discussion = $DiscussionModel->GetID($discussionid);
   
   if (!$Discussion)
      return FALSE;
   
   // Detect closed state
   if ($Discussion->Closed)
      $discussion_closed = TRUE;
   
   $CommentModel->SetWatch($Discussion, $CommentData->NumRows(), 0, $Discussion->CountComments);
}

/**
 * Get avatar/photo URL for a comment user.
 *
 * @param mixed $data UserID (int) or object containing user data.
 * @return string HTML for user photo.
 */
function glue_get_photo($data, $Options = array()) {
   if (is_numeric($data)) {
      global $wpdb;
      $data = $wpdb->get_row("SELECT UserID as InsertUserID, Name as InsertName, Photo as InsertPhoto, Email as InsertEmail, DateFirstVisit FROM ".VANILLA_PREFIX."User WHERE UserID = $data");
   }
   
   $Px = (GetValue('InsertUserID', $data)) ? array('Px' => 'Insert') : array('Px' => 'Guest');   
   $Options = array_merge($Options, $Px);
   
   return UserPhoto($data, $Options);
}

/**
 * Get avatar/photo URL for a comment user.
 *
 * @param object $comment
 * @return string Url path to user profile.
 */
function glue_get_url($comment) {
   $UserID = GetValue('UserID', $comment);
   return ($UserID) ? '/profile/'.$UserID.'/'.rawurlencode(GetValue('InsertName', $comment)) : GetValue('GuestUrl', $comment); 
}

/**
 * Logout current user.
 */
function glue_logout() {
   Gdn::Session()->End();
}

/**
 * Use Vanilla profile, not WordPress.
 */
function glue_block_profile() {
   $result = stripos($_SERVER['REQUEST_URI'], 'profile.php');
   if ($result !== FALSE) {
      wp_redirect(get_option('siteurl') . '/profile');
   }
}