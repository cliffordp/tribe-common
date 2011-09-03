<?php
/**
 * TribeEventsRecurrenceMeta
 * 
 * WordPress hooks and filters controlling event recurrence
 * @author John Gadbois
 */
class TribeEventsRecurrenceMeta {
	const UPDATE_TYPE_ALL = 1;
	const UPDATE_TYPE_FUTURE = 2;
	const UPDATE_TYPE_SINGLE = 3;
	
	public static function init() {
		add_action( 'tribe_events_update_meta', array( __CLASS__, 'updateRecurrenceMeta' ), 1, 3 );
		add_action( 'tribe_events_date_display', array( __CLASS__, 'loadRecurrenceData' ) );
		add_action('trash_post', array( __CLASS__, 'deleteRecurringEvent'));		

		add_action('pre_post_update', array( __CLASS__, 'maybeBreakFromSeries' ));
		add_action( 'admin_notices', array( __CLASS__, 'showRecurrenceErrorFlash') );
		add_action( 'tribe_recurring_event_error', array( __CLASS__, 'setupRecurrenceErrorMsg'), 10, 2);

      add_filter( 'tribe_get_event_link', array( __CLASS__, 'addDateToEventPermalink'), 10, 2);
      add_filter( 'post_row_actions', array( __CLASS__, 'removeQuickEdit'), 10, 2);
	}	

   public static function removeQuickEdit( $actions, $post ) {
      if( tribe_is_recurring_event( $post ) ) {
         unset($actions['inline hide-if-no-js']);
      }

      return $actions;
   }

   public static function addDateToEventPermalink($permalink, $the_post) {
      global $post;
      $post = $the_post ? $the_post : $post;

      if(tribe_is_recurring_event($post->ID)) {
         $events = TribeEvents::instance();
			if( '' == get_option('permalink_structure') || false == $events->getOption('useRewriteRules',true) )
            return add_query_arg('eventDate', TribeDateUtils::dateOnly( $post->EventStartDate ), $eventUrl );						
         else
            return $permalink . TribeDateUtils::dateOnly( $post->EventStartDate );					
      } else {
         return $permalink;
      }
   }
	
	/**
	 * Update event recurrence when a recurring event is saved
	 * @param integer $event_id id of the event to update
	 * @param array $data data defining the recurrence of this event
	 * @return void  
	 */
	public static function updateRecurrenceMeta($event_id, $data) {
		// save recurrence
		$recurrence_meta = $data['recurrence'];
	
		if( TribeEventsRecurrenceMeta::isRecurrenceValid( $event, $recurrence_meta ) ) {
			update_post_meta($event_id, '_EventRecurrence', $recurrence_meta);				
			TribeEventsRecurrenceMeta::saveEvents($event_id);
		}
	}

	/**
	 * Displays the events recurrence form on the event editor screen
	 * @param integer $postId ID of the current event 
	 * @return void  
	 */	
	public static function loadRecurrenceData($postId) {
		// convert array to variables that can be used in the view
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));

		$premium = TribeEventsPro::instance();		
		include( TribeEventsPro::instance()->pluginPath . 'admin-views/event-recurrence.php' );
	}		
	
	/**
	 * Deletes a SINGLE occurrence of a recurring event
	 * @param integer $postId ID of the event that may have an occurence deleted from it
	 * @return void  
	 */	
	public static function deleteRecurringEvent($postId) {
		$occurrenceDate = $_REQUEST['eventDate'];
		
		if( $occurrenceDate ) {
			self::removeOccurrence( $postId, $occurrenceDate );
			wp_redirect( add_query_arg( 'post_type', TribeEvents::POSTTYPE, admin_url( 'edit.php' ) ) );
			exit();
		}
	}
	
	/**
	 * Handles updating recurring events. 
	 * @param integer $postId ID of the event being updated
	 * @return void  
	 */		
	public static function maybeBreakFromSeries( $postId ) {
		// make new series for future events
		if($_POST['recurrence_action'] && $_POST['recurrence_action'] == TribeEventsRecurrenceMeta::UPDATE_TYPE_FUTURE) {
			// if this is the first event in the series, then we don't need to break it into two series
			if( $_POST['EventStartDate'] != TribeDateUtils::dateOnly( TribeEvents::getRealStartDate($postId) )) {
				// move recurrence end to the last date of the series before today
				$numOccurrences = self::adjustRecurrenceEnd( $postId, $_POST['EventStartDate'] );			

				// prune future occurrences on original event
				self::removeFutureOccurrences( $postId, $_POST['EventStartDate'] );

				if ($_POST['recurrence']['end-type'] == 'After') {
					// num occurrences for new series is total occurrences minus occurrences still in original series
					$_POST['recurrence']['end-count'] = $_POST['recurrence']['end-count'] - $numOccurrences;
				}

				// redirect form to new event
				$post = self::cloneEvent( $_POST );

				// remove past occurrences of new event
				self::removePastOccurrences( $post );
				// actual event end time potentially needs to be adjusted up
				self::adjustEndDate( $post );

				// clear this so no infinite loop - clear after new post is inserted so it can be used in the recurrence logic
				$_POST['recurrence_action'] = null;

				// redirect back to event screen
				wp_redirect('post.php?post=' . $post . '&action=edit&message=1');
				exit();
			}
		// break from series			
		} else if($_POST['recurrence_action'] && $_POST['recurrence_action'] == TribeEventsRecurrenceMeta::UPDATE_TYPE_SINGLE) {
			// new event should have no recurrence
			$_REQUEST['recurrence'] = $_POST['recurrence'] = null;

			// create new event
			$post = self::cloneEvent( $_POST );
			
			// remove this occurrance from the original series
			self::removeOccurrence( $postId, $_POST['EventStartDate'] );

			// the end date on original series will need to be moved if it was the first event in the series removed
			self::adjustEndDate( $postId );

			$_POST['recurrence_action'] = null;

			// redirect back to event screen
			wp_redirect('post.php?post=' . $post . '&action=edit&message=1');		
			exit();
		}
	}


	/**
	 * Setup an error message if there is a problem with a given recurrence.  
	 * The message is saved in an option to survive the page load and is deleted after being displayed 
 	 * @param array $event The event object that is being saved
	 * @param array $msg The message to display 
	 * @return void
	 */	
	public static function setupRecurrenceErrorMsg( $event, $msg ) {
		global $current_screen;

		// only do this when editing events
		if( is_admin() && $current_screen->id == TribeEvents::POSTTYPE ) {
			update_post_meta($event->ID, 'sp_flash_message', $msg);
		}
	}
	

	/**
	 * Display an error message if there is a problem with a given recurrence and clear it from the cache 
	 * @return void
	 */		
	public static function showRecurrenceErrorFlash(){
		global $post, $current_screen;

		if ( $current_screen->base == 'post' && $current_screen->post_type == TribeEvents::POSTTYPE ) {
			$msg = get_post_meta($post->ID, 'sp_flash_message', true);

			if ($msg) {
				echo '<div class="error"><p>Recurrence not saved: ' . $msg . '</p></div>';
			   delete_post_meta($post->ID, 'sp_flash_message');
		   }		  
	   }
	}

	/**
	 * Convenience method for turning event meta into keys available to turn into PHP variables 
	 * @param integer $postId ID of the event being updated
	 * @param $recurrenceData array The actual recurrence data
	 * @return void  
	 */		
	public static function getRecurrenceMeta( $postId, $recurrenceData = null ) {
		if (!$recurrenceData )
			$recurrenceData = get_post_meta($postId, '_EventRecurrence', true);

		$recArray = array();

		if ( $recurrenceData ) {
			$recArray['recType'] = $recurrenceData['type'];
			$recArray['recEndType'] = $recurrenceData['end-type'];
			$recArray['recEnd'] = $recurrenceData['end'];
			$recArray['recEndCount'] = $recurrenceData['end-count'];

			$recArray['recCustomType'] = $recurrenceData['custom-type'];
			$recArray['recCustomInterval'] = $recurrenceData['custom-interval'];
			
			// the following two fields are just text fields used in the display
			$recArray['recCustomTypeText'] = $recurrenceData['custom-type-text'];
			$recArray['recOccurrenceCountText'] = $recurrenceData['occurrence-count-text'];

			$recArray['recCustomWeekDay'] = $recurrenceData['custom-week-day'];

			//$recArray['recCustomMonthType'] = $recurrenceData['custom-months-type'];
			//$recArray['recCustomMonthDayOfMonth'] = $recurrenceData['custom-month-day-of-month'];
			$recArray['recCustomMonthNumber'] = $recurrenceData['custom-month-number'];
			$recArray['recCustomMonthDay'] = $recurrenceData['custom-month-day'];

			$recArray['recCustomYearMonth'] = $recurrenceData['custom-year-month'] ? $recurrenceData['custom-year-month'] : array();
			$recArray['recCustomYearFilter'] = $recurrenceData['custom-year-filter'];
			$recArray['recCustomYearMonthNumber'] = $recurrenceData['custom-year-month-number'];
			$recArray['recCustomYearMonthDay'] = $recurrenceData['custom-year-month-day'];
		} else {
			$recArray['recCustomYearMonth'] = array();
		}

		return $recArray;
	}

	
	/**
	 * Deletes a single occurrence of an event
 	 * @param integer $postId ID of the event that occurrence will be deleted from 
	 * @param string $date date of occurrence to delete 
	 * @return void  
	 */			
	private static function removeOccurrence( $postId, $date ) {
		$startDate = TribeEvents::getRealStartDate($postId);
		$date = TribeDateUtils::addTimeToDate( $date, TribeDateUtils::timeOnly($startDate) );

		delete_post_meta( $postId, '_EventStartDate', $date );
	}
	
	/**
	 * Removes all occurrences of an event that are after a given date
 	 * @param integer $postId ID of the event that occurrences will be deleted from 
	 * @param string $date date to delete occurrences after, current date if not specified  
	 * @return void  
	 */				
	private static function removeFutureOccurrences( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();
	
		$occurrences = get_post_meta($postId, '_EventStartDate');
		
		foreach($occurrences as $occurrence) {
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) >= $date ) {
				delete_post_meta($postId, '_EventStartDate', $occurrence);
			}
		}
	}
	
	/**
	 * Removes all occurrences of an event that are before a given date
 	 * @param integer $postId ID of the event that occurrences will be deleted from 
	 * @param string $date date to delete occurrences before, current date if not specified  
	 * @return void  
	 */		
	private static function removePastOccurrences( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();
		$occurrences = get_post_meta($postId, '_EventStartDate');
		
		foreach($occurrences as $occurrence) {
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) < $date ) {
				delete_post_meta($postId, '_EventStartDate', $occurrence);
			}
		}
	}	
		
	
	/**
	 * Adjust the end date of a series to be the start date of the last instance after a given date.  This function is used
	 * when a series is split into two and the original series needs to be shortened to the start date of the new series
 	 * @param integer $postId ID of the event that will have it's series end adjusted 
	 * @param string $date new end date for the series  
	 * @return the number of occurrences in the shortened series.  This is useful if you need to know how many occurrences
	 * the new series should have  
	 */		
	private static function adjustRecurrenceEnd( $postId, $date = null ) {
		$date = $date ? strtotime($date) : time();

		$occurrences = get_post_meta($postId, '_EventStartDate');
		$occurrenceCount = 0;
		sort($occurrences);
		
		if( is_array($occurrences) && sizeof($occurrences) > 0 ) {
			$prev = $occurrences[0];
		}
				  
		foreach($occurrences as $occurrence) {
			$occurrenceCount++; // keep track of how many we are keeping
			if (strtotime(TribeDateUtils::dateOnly($occurrence)) > $date ) {
				$recurrenceMeta = get_post_meta($postId, '_EventRecurrence', true);
				$recurrenceMeta['end'] = date(DateSeriesRules::DATE_ONLY_FORMAT, strtotime($prev));

				update_post_meta($postId, '_EventRecurrence', $recurrenceMeta);
				break;
			}
			
			$prev = $occurrence;
		}
		
		// useful for knowing how many occurrences are needed for new series		
		return $occurrenceCount; 
	}

	/**
	 * Change the EventEndDate of a recurring event.  This is needed when a recurring series is split into two series.  The new series
	 * has to have its end date adjusted.  Note:  EventEndDate is only set once per recurring event and is the end date of the first occurrence.
	 * Subsequent occurrences have a calculated event date based on duration of the first event (EventEndDate - EventStartDate)
 	 * @param integer $postId ID of the event that will have it's series end date adjusted 
	 * @return void
	 */		
	private static function adjustEndDate( $postId ) {
		$occurrences = get_post_meta($postId, '_EventStartDate');
		sort($occurrences);

		$duration = get_post_meta($postId, '_EventDuration', true);
		
		if( is_array($occurrences) && sizeof($occurrences) > 0 ) {
			update_post_meta($postId, '_EventEndDate', date(DateSeriesRules::DATE_FORMAT, strtotime($occurrences[0]) + $duration));
		}	
	}	
	
	/**
	 * Clone an event when splitting up a recurring series
 	 * @param array $data The event information for the original event 
	 * @return void
	 */			
	private static function cloneEvent( $data ) {
		$tribe_ecp = TribeEvents::instance();
		
		$data['ID'] = null;
		$new_event = wp_insert_post($data);
		return $new_event;
	}		

	/**
	 * Recurrence validation method.  This is checked after saving an event, but before splitting a series out into multiple occurrences
 	 * @param array $event The event object that is being saved
	 * @param array $recurrence_meta Recurrence information for this event 
	 * @return void
	 */	
	public static function isRecurrenceValid( $event, $recurrence_meta  ) {
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta( $event->ID, $recurrence_meta ));
		$valid = true;
		$errorMsg = '';

		if($recType == "Custom" && $recCustomType == "Monthly" && ($recCustomMonthDay == '-' || $recCustomMonthNumber == '')) {
			$valid = false;
			$errorMsg = __('Monthly custom recurrences cannot have a dash set as the day to occur on.');
		} else if($recType == "Custom" && $recCustomType == "Yearly" && $recCustomYearMonthDay == '-') {
			$valid = false;
			$errorMsg = __('Yearly custom recurrences cannot have a dash set as the day to occur on.');			
		}

		if ( !$valid ) {
			do_action( 'tribe_recurring_event_error', $event, $errorMsg );
		}

		return $valid;
	}

	/**
	 * Do the actual work of saving a recurring series of events
 	 * @param array $postId The event that is being saved 
	 * @return void
	 */		
	public static function saveEvents( $postId) {
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));
		$rules = TribeEventsRecurrenceMeta::getSeriesRules($postId);

		// use the recurrence start meta if necessary because we can't guarantee which order the start date will come back in
		$recStart = strtotime(get_post_meta($postId, '_EventStartDate', true));
		$eventEnd = strtotime(get_post_meta($postId, '_EventEndDate', true));
		$duration = $eventEnd - $recStart;

		$recEnd = $recEndType == "On" ? strtotime($recEnd) : $recEndCount - 1; // subtract one because event is first occurrence

		// different update types
		delete_post_meta($postId, '_EventStartDate');
		delete_post_meta($postId, '_EventEndDate');
		delete_post_meta($postId,'_EventDuration');

		// add back original start and end date
		add_post_meta($postId,'_EventStartDate', date(DateSeriesRules::DATE_FORMAT, $recStart));
		add_post_meta($postId,'_EventEndDate', date(DateSeriesRules::DATE_FORMAT, $eventEnd));
		add_post_meta($postId,'_EventDuration', $duration);

		if ( $recType != "None") {
			$recurrence = new TribeRecurrence($recStart, $recEnd, $rules, $recEndType == "After");
			$dates = (array) $recurrence->getDates();

			// add meta for all dates in recurrence
			foreach($dates as $date) {
				add_post_meta($postId,'_EventStartDate', date(DateSeriesRules::DATE_FORMAT, $date));
			}
		}
	}

	/**
	 * Decide which rule set to use for finding all the dates in an event series
 	 * @param array $postId The event to find the series for
	 * @return void
	 */		
	public static function getSeriesRules($postId) {
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));
		$rules = null;

		if(!$recCustomInterval)
			$recCustomInterval = 1;

		if($recType == "Every Day" || ($recType == "Custom" && $recCustomType == "Daily")) {
			$rules = new DaySeriesRules($recType == "Every Day" ? 1 : $recCustomInterval);
		} else if($recType == "Every Week") {
			$rules = new WeekSeriesRules(1);
		} else if ($recType == "Custom" && $recCustomType == "Weekly") {
			$rules = new WeekSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomWeekDay);
		} else if($recType == "Every Month") {
			$rules = new MonthSeriesRules(1);
		} else if($recType == "Custom" && $recCustomType == "Monthly") {
			$recCustomMonthDayOfMonth = is_numeric($recCustomMonthNumber) ? array($recCustomMonthNumber) : null;
			$recCustomMonthNumber = self::ordinalToInt($recCustomMonthNumber);
			$rules = new MonthSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomMonthDayOfMonth, $recCustomMonthNumber, $recCustomMonthDay);
		} else if($recType == "Every Year") {
			$rules = new YearSeriesRules(1);
		} else if($recType == "Custom" && $recCustomType == "Yearly") {
			$rules = new YearSeriesRules($recCustomInterval ? $recCustomInterval : 1, $recCustomYearMonth, $recCustomYearFilter ? $recCustomYearMonthNumber : null, $recCustomYearFilter ? $recCustomYearMonthDay : null);
		}

		return $rules;
	}
	
	/**
	 * Convert the event recurrence meta into a human readable string
 	 * @param array $postId The recurring event
	 * @return The human readable string
	 */			
	public static function recurrenceToText( $postId = null ) {
		$text = "";
		$custom_text = "";
		$occurrence_text = "";
		
		if( $postId == null ) {
			global $post;
			$postId = $post->ID;
		}
		
		extract(TribeEventsRecurrenceMeta::getRecurrenceMeta($postId));
		
		if ($recType == "Every Day") {
			$text = __("Every day"); 
			$occurrence_text = sprintf(_n(" for %d day", " for %d days", $recEndCount), $recEndCount);
			$custom_text = ""; 
		} else if($recType == "Every Week") {
			$text = __("Every week");
			$occurrence_text = sprintf(_n(" for %d week", " for %d weeks", $recEndCount), $recEndCount);		
		} else if($recType == "Every Month") {
			$text = __("Every month");
			$occurrence_text = sprintf(_n(" for %d month", " for %d months", $recEndCount), $recEndCount);						
		} else if($recType == "Every Year") {
			$text = __("Every year");
			$occurrence_text = sprintf(_n(" for %d year", " for %d years", $recEndCount), $recEndCount);					
		} else if ($recType == "Custom") {
			if ($recCustomType == "Daily") {
				$text = $recCustomInterval == 1 ? 
					__("Every day") : 
					sprintf(__("Every %d days"), $recCustomInterval);
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount), $recEndCount);	
			} else if ($recCustomType == "Weekly") {
				$text = $recCustomInterval == 1 ? 
					__("Every week") : 
					sprintf(__("Every %d weeks"), $recCustomInterval);	
				$custom_text = sprintf(__(" on %s"), self::daysToText($recCustomWeekDay));
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount), $recEndCount);	
			} else if ($recCustomType == "Monthly") {
				$text = $recCustomInterval == 1 ? 
					__("Every month") : 
					sprintf(__("Every %d months"), $recCustomInterval);								
               $number_display = is_numeric($recCustomMonthNumber) ? TribeDateUtils::numberToOrdinal( $recCustomMonthNumber ) : strtolower($recCustomMonthNumber); 
				$custom_text = sprintf(__(" on the %s %s"), $number_display,  is_numeric($recCustomMonthNumber) ? __("day") : self::daysToText($recCustomMonthDay));
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount), $recEndCount);	
			} else if ($recCustomType == "Yearly") {
				$text = $recCustomInterval == 1 ? 
					__("Every year") : 
					sprintf(__("Every %d years"), $recCustomInterval);												
				
				$customYearNumber = $recCustomYearMonthNumber != -1 ? TribeDateUtils::numberToOrdinal($recCustomYearMonthNumber) : __("last");
				
				$day = $recCustomYearFilter ? $customYearNumber : TribeDateUtils::numberToOrdinal( date('j', strtotime( TribeEvents::getRealStartDate( $postId ) ) ) );
				$of_week = $recCustomYearFilter ? self::daysToText($recCustomYearMonthDay) : "";
				$months = self::monthsToText($recCustomYearMonth);
				$custom_text = sprintf(__(" on the %s %s of %s"), $day, $of_week, $months);				
				$occurrence_text = sprintf(_n(", recurring %d time", ", recurring %d times", $recEndCount), $recEndCount);	
			}
		}
		
		// end text
		if ( $recEndType == "On" ) {
			$endText = sprintf(__(" until %s"), date_i18n(get_option('date_format'), strtotime($recEnd))) ;
		} else {
			$endText = $occurrence_text;
		}
		
		return sprintf(__('%s%s%s'), $text, $custom_text, $endText);
	}
	
	/**
	 * Convert an array of day ids into a human readable string
 	 * @param array $days The day ids
	 * @return The human readable string
	 */			
	private static function daysToText($days) {
		$day_words = array(__("Monday"), __("Tuesday"), __("Wednesday"), __("Thursday"), __("Friday"), __("Saturday"), __("Sunday"));
		$count = sizeof($days);
		$day_text = "";
		
		for($i = 0; $i < $count ; $i++) {
			if ( $count > 2 && $i == $count - 1 ) {
				$day_text .= __(", and ");
			} else if ($count == 2 && $i == $count - 1) {
				$day_text .= __(" and ");
			} else if ($count > 2 && $i > 0) {
				$day_text .= __(", ");
			}

			$day_text .= $day_words[$days[$i]-1] ? $day_words[$days[$i]-1] : "day";
		}
		
		return $day_text;
	}
	
	/**
	 * Convert an array of month ids into a human readable string
 	 * @param array $months The month ids
	 * @return The human readable string
	 */		
	private static function monthsToText($months) {
		$month_words = array(__("January"), __("February"), __("March"), __("April"), 
			 __("May"), __("June"), __("July"), __("August"), __("September"), __("October"), __("November"), __("December"));
		$count = sizeof($months);
		$month_text = "";
		
		for($i = 0; $i < $count ; $i++) {
			if ( $count > 2 && $i == $count - 1 ) {
				$month_text .= __(", and ");
			} else if ($count == 2 && $i == $count - 1) {
				$month_text .= __(" and ");				
			} else if ($count > 2 && $i > 0) {
				$month_text .= __(", ");
			}
			
			$month_text .= $month_words[$months[$i]-1];
		}
		
		return $month_text;
	}	
	
	/**
	 * Convert an ordinal from an ECP recurrence series into an integer
 	 * @param string $ordinal The ordinal number
	 * @return An integer representation of the ordinal
	 */		
	private static function ordinalToInt($ordinal) {
		switch( $ordinal ) {
			case "First": return 1;
			case "Second": return 2;
			case "Third": return 3;
			case "Fourth": return 4;
			case "Last": return -1;
		   default: return null;
		}
	}
}