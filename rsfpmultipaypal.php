<?php
/**
 * @package       RSForm!Pro
 * @copyright (C) 2007-2019 www.rsjoomla.com
 * @license       GPL, http://www.gnu.org/copyleft/gpl.html
 */

defined( '_JEXEC' ) or die( 'Restricted access' );
define( 'RSFORM_FIELD_MULTI_PAYMENT_PAYPAL', 520 );

class plgSystemRsfpmultipaypal extends JPlugin {
	protected $componentId = RSFORM_FIELD_MULTI_PAYMENT_PAYPAL;
	protected $componentValue = 'multipaypal';
	protected $log = array();

	protected $autoloadLanguage = true;

	public function onRsformBackendAfterCreateFieldGroups( &$fieldGroups, $self ) {
		$formId                           = JFactory::getApplication()->input->getInt( 'formId' );
		$exists                           = RSFormProHelper::componentExists( $formId, RSFORM_FIELD_MULTI_PAYMENT_PAYPAL );
		$fieldGroups['payment']->fields[] = (object) array(
				'id'     => RSFORM_FIELD_MULTI_PAYMENT_PAYPAL,
				'name'   => JText::_( 'RSFP_MULTI_PAYPAL_COMPONENT' ),
				'icon'   => 'rsficon rsficon-paypal',
				'exists' => $exists ? $exists[0] : false
		);
	}

	/**
	 * Add a grand total and tax placeholder
	 *
	 * @param $args
	 */
	public function onRsformAfterCreatePlaceholders( $args ) {
		$choosePayment = RSFormProHelper::componentExists( $args['form']->FormId, RSFORM_FIELD_PAYMENT_CHOOSE );
		$hasPaypal     = RSFormProHelper::componentExists( $args['form']->FormId, $this->componentId );
		if ( $choosePayment || $hasPaypal ) {
			if ( $choosePayment ) {
				$properties = RSFormProHelper::getComponentProperties( $choosePayment[0] );
				$fieldName  = $properties['NAME'];
				if ( ! isset( $args['submission']->values[ $fieldName ] ) ) {
					return;
				}
				if ( $args['submission']->values[ $fieldName ] != $this->componentValue ) {
					return;
				}
			}
			$grandTotal     = $this->calcTax( $args['submission']->values['rsfp_Total'], RSFormProHelper::getConfig( 'multipaypal.tax.value' ), RSFormProHelper::getConfig( 'multipaypal.tax.type' ) );
			$placeholders   = &$args['placeholders'];
			$values         = &$args['values'];
			$placeholders[] = '{grandtotal}';
			$values[]       = $this->number_format( $grandTotal );
			$placeholders[] = '{tax}';
			$values[]       = $this->number_format( $grandTotal - (float) $args['submission']->values['rsfp_Total'] );
		}
	}

	/**
	 * @param $placeholders
	 * @param $componentId
	 *
	 * @return mixed
	 * @since 2.0.0
	 */
	public function onRsformAfterCreateQuickAddPlaceholders( &$placeholders, $componentId ) {
		if ( $componentId == $this->componentId ) {
			$placeholders['display'][] = '{grandtotal}';
			$placeholders['display'][] = '{tax}';
		}

		return $placeholders;
	}

	public function onRsformGetPayment( &$items, $formId ) {
		if ( $components = RSFormProHelper::componentExists( $formId, $this->componentId ) ) {
			$data        = RSFormProHelper::getComponentProperties( $components[0] );
			$item        = new stdClass();
			$item->value = $this->componentValue;
			$item->text  = $data['LABEL'];
			if ( $tax = RSFormProHelper::getConfig( 'multipaypal.tax.value' ) ) {
				$item->tax      = $tax;
				$item->tax_type = RSFormProHelper::getConfig( 'multipaypal.tax.type' ) == '0' ? 'percent' : 'fixed';

			}
			// add to array
			$items[] = $item;
		}
	}

	public function onRsformDoPayment( $payValue, $formId, $SubmissionId, $price, $products, $code ) {
		// execute only for our plugin
		if ( $payValue != $this->componentValue ) {
			return;
		}
		if ( $price > 0 ) {
			list( $replace, $with ) = RSFormProHelper::getReplacements( $SubmissionId );
			$args = array(
					'cmd'           => '_xclick',
					'business'      => RSFormProHelper::getConfig( 'multipaypal.email' ),
					'item_name'     => implode( ', ', $products ),
					'currency_code' => RSFormProHelper::getConfig( 'payment.currency' ),
					'amount'        => number_format( $price, 2, '.', '' ),
					'notify_url'    => JUri::root() . 'index.php?option=com_rsform&formId=' . $formId . '&task=plugin&plugin_task=multipaypal.notify&code=' . $code,
					'charset'       => 'utf-8',
					'lc'            => RSFormProHelper::getConfig( 'multipaypal.language' ) ? RSFormProHelper::getConfig( 'multipaypal.language' ) : 'US',
					'bn'            => 'RSJoomla_SP',
					'return'        => JUri::root() . 'index.php?option=com_rsform&formId=' . $formId . '&task=plugin&plugin_task=multipaypal.return'
			);
			// Add cancel URL
			if ( $cancel = RSFormProHelper::getConfig( 'multipaypal.cancel' ) ) {
				$args['cancel_return'] = str_replace( $replace, $with, $cancel );
			}
			// Add return URL
			if ( $return = RSFormProHelper::getConfig( 'multipaypal.return' ) ) {
				$args['return'] = str_replace( $replace, $with, $return );
			}
			// Add tax
			if ( $tax = RSFormProHelper::getConfig( 'multipaypal.tax.value' ) ) {
				if ( RSFormProHelper::getConfig( 'multipaypal.tax.type' ) ) {
					$args['tax'] = $tax;
				} else {
					$args['tax_rate'] = $tax;
				}
			}
			// Get a new instance of the PayPal object. This is used so that we can programatically change values sent to PayPal through the "Scripts" areas.
			$paypal = RSFormProMultiPayPal::getInstance();
			// If any options have already been set, use this to override the ones used here
			$paypal->args = array_merge( $args, $paypal->args );
			JFactory::getApplication()->redirect( $paypal->url . '?' . http_build_query( $paypal->args, '', '&' ) );
		}
	}

	protected function payPalReturn( $formId ) {
		// Get session object
		$session = JFactory::getSession();
		$app     = JFactory::getApplication();
		// Get data from session
		$formparams = $session->get( 'com_rsform.formparams.formId' . $formId );
		if ( $formparams && $formparams->redirectUrl ) {
			// Mark form as processed
			$formparams->formProcessed = true;
			// Store new session data
			$session->set( 'com_rsform.formparams.formId' . $formId, $formparams );
			// Redirect
			$app->redirect( $formparams->redirectUrl );
		}
	}

	public function onRsformBackendAfterShowConfigurationTabs( $tabs ) {
		$tabs->addTitle( JText::_( 'RSFP_MULTI_PAYPAL_LABEL' ), 'form-multipaypal' );
		$tabs->addContent( $this->configurationScreen() );
	}

	public function onRsformDefineHiddenComponents( &$hiddenComponents ) {
		$hiddenComponents[] = $this->componentId;
	}

	/**
	 * Helper function to write log entries
	 */
	protected function writeLog() {
		// Need to separate IPN entries
		$this->addLogEntry( "----------------------------- \n" );
		$config   = JFactory::getConfig();
		$log_path = $config->get( 'log_path' ) . '/rsformpro_multipaypal_log.php';
		$log      = implode( "\n", $this->log );
		/**
		 * If it's the first time we write in it, we need to add die() at the beginning of the file
		 */
		if ( is_writable( $config->get( 'log_path' ) ) ) {
			if ( ! file_exists( $log_path ) ) {
				file_put_contents( $log_path, "<?php die(); ?>\n" );
			}
			/**
			 * we start appending log entries
			 */
			file_put_contents( $log_path, $log, FILE_APPEND );
		}
	}

	/**
	 * Helper function to add messages to the log
	 *
	 * @param $message
	 */
	protected function addLogEntry( $message ) {
		$this->log[] = JFactory::getDate()->toSql() . ' : ' . $message;
	}

	public function onRsformFrontendSwitchTasks() {
		$input = JFactory::getApplication()->input;
		JLog::addLogger( array( 'text_file' => 'rsform_payme.php' ), JLog::ALL, array( 'com_rsform' ) );
		// Notification receipt from Paypal
		if ( $input->getString( 'plugin_task', '' ) == 'multipaypal.notify' ) {
			$code   = $input->getCmd( 'code' );
			$formId = $input->getInt( 'formId' );
			$this->addLogEntry( 'IPN received from PayPal' );
			$validation = $this->validateIpn();
			if ( $validation['error'] ) {
				$message = 'Validation failed -> ' . $validation['reason'];
				$this->addLogEntry( $message );
				$this->addLogEntry( 'POST data is:' );
				$this->addLogEntry( print_r( $_POST, true ) );
				$this->addLogEntry( 'Validation data is:' );
				$this->addLogEntry( print_r( $this->_getValidationFields(), true ) );
				$this->writeLog();
				// Throw a server error so that the IPN will resend
				if ( ! empty( $validation['server'] ) ) {
					header( $_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500 );
					flush();
					JFactory::getApplication()->close();
				}

				return false;
			}
			/**
			 * In case you issue a refund, there is no need to resend emails
			 */
			if ( in_array( $input->getString( 'reason_code', '' ), array( 'refund', 'chargeback' ) ) ) {
				$message = 'Issued Refund for: ' . $input->getString( 'payer_email' );
				$this->addLogEntry( $message );
				$this->writeLog();

				return false;
			}
			$SubmissionId = $this->_getSubmissionId( $formId, $code );
			if ( $SubmissionId ) {
				$message = 'Payment accepted from: ' . $input->getString( 'payer_email' );
				$this->addLogEntry( $message );
				$this->addLogEntry( 'Update database' );
				$db    = JFactory::getDbo();
				$query = $db->getQuery( true );
				$query->update( $db->qn( '#__rsform_submission_values' ) )
					  ->set( $db->qn( 'FieldValue' ) . ' = ' . $db->q( 1 ) )
					  ->where( $db->qn( 'FieldName' ) . ' = ' . $db->q( '_STATUS' ) )
					  ->where( $db->qn( 'FormId' ) . ' = ' . $db->q( $formId ) )
					  ->where( $db->qn( 'SubmissionId' ) . ' = ' . $db->q( $SubmissionId ) );
				$db->setQuery( $query );
				$db->execute();
				$this->addLogEntry( 'Successfully updated database' );
				// Add txn_id to _TRANSACTION_ID field
				if ( $txn_id = $input->getString( 'txn_id' ) ) {
					$query = $db->getQuery( true );
					$query->update( $db->qn( '#__rsform_submission_values' ) )
						  ->set( $db->qn( 'FieldValue' ) . ' = ' . $db->q( $txn_id ) )
						  ->where( $db->qn( 'FieldName' ) . ' = ' . $db->q( '_TRANSACTION_ID' ) )
						  ->where( $db->qn( 'FormId' ) . ' = ' . $db->q( $formId ) )
						  ->where( $db->qn( 'SubmissionId' ) . ' = ' . $db->q( $SubmissionId ) );
					$db->setQuery( $query );
					$db->execute();
				}
				JFactory::getApplication()->triggerEvent( 'onRsformAfterConfirmPayment', array( $SubmissionId ) );
				$this->addLogEntry( 'Payment confirmed' );
				$this->writeLog();
			}
			jexit( 'ok' );
		}
		if ( $input->getString( 'plugin_task', '' ) == 'multipaypal.return' ) {
			$formId = $input->getInt( 'formId' );
			$this->payPalReturn( $formId );
		}
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function validateIpn() {
		/**
		 * Set the URL
		 */
		$url = RSFormProHelper::getConfig( 'multipaypal.test' ) ? 'https://ipnpb.paypal.com/cgi-bin/webscr' : 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
		/**
		 * Build a default array for errors
		 */
		$array = array(
				'error'  => false,
				'reason' => null,
		);
		/**
		 * Start the IPN Verifaction here,
		 * Set up the fields to connect to the PayPal validation service
		 * and log each step individually in the log.
		 */
		$message = sprintf( 'Connecting to %s to verify if PayPal response is valid.', $url );
		$this->addLogEntry( $message );
		/***
		 * Instantiate JHttpFactory;
		 */
		$http = JHttpFactory::getHttp();
		/**
		 * Use the RSFP! Version and the URL of the website to set the User Agent
		 */
		require_once JPATH_ADMINISTRATOR . '/components/com_rsform/helpers/version.php';
		$version = (string) new RSFormProVersion;
		$http->setOption( 'userAgent', "RSForm! Pro/$version" );
		/**
		 * Although Joomla! can parse the fields by itself,
		 * we can use this function to return a string.
		 */
		$req = $this->_buildPostData();
		try {
			$response = $http->post( $url, $req, array(), 5 );
			$code     = $response->code;
			if ( $code != 200 ) {
				throw new Exception( sprintf( 'Connection Error: %d', $response->code ) );
			}
			$body = (string) $response->body;
			$body = trim( $body );
			if ( ! strlen( $body ) ) {
				throw new Exception( 'Response does not contain a valid body' );
			}
		} catch ( Exception $e ) {
			return array(
					'server' => true,
					'error'  => true,
					'reason' => $e->getMessage(),
			);
		}
		/**
		 * In case the URL returns a different response, something went wrong.
		 * return here
		 */
		if ( ! in_array( $body, array( 'INVALID', 'VERIFIED' ) ) ) {
			$array['error']  = true;
			$array['reason'] = sprintf( 'PayPal response is not valid! Should be either VERIFIED or INVALID, received %s', $body );

			return $array;
		}
		/**
		 * In case the response is invalid,
		 * return an error
		 */
		if ( strcmp( $body, 'INVALID' ) == 0 ) {
			$array['error']  = true;
			$array['reason'] = "PayPal reported an invalid transaction.";

			return $array;
		}
		/**
		 * Else, validate the transaction
		 */
		if ( strcmp( $body, "VERIFIED" ) == 0 ) {
			$this->addLogEntry( 'PayPal reported a valid transaction.' );
		}
		/**
		 * Grab the information we need for validation with J-Input
		 * _getValidationFields() returns an array :
		 * array(
		 *  'currency' => 'USD',
		 *  'receiver_email' => 'email@email.com',
		 *  'reason' => 'refund'
		 * )
		 */
		$validation_fields = $this->_getValidationFields();
		/**
		 * We verify the amount paid only if it's a purchase transaction.
		 *
		 * The refund amount may vary due to the fact that partial refunds are available
		 */
		if ( $validation_fields['reason'] !== 'refund' ) {
			/**
			 * Grab the Submission ID of the order
			 * return error if the submission does not exist
			 */
			$SubmissionId = $this->_getSubmissionId( $validation_fields['formId'], $validation_fields['code'] );
			if ( ! $SubmissionId ) {
				$array['error']  = true;
				$array['reason'] = 'Submission does not exist.';
			}
			/**
			 * Use SubmissionId to get the total
			 * return error if it's null
			 */
			$total = $this->_getTotal( $SubmissionId );
			if ( $total === null ) {
				$array['error']  = true;
				$array['reason'] = 'The total price of the order could not be verified.';

				return $array;
			}
			/**
			 * If we have an amount, make sure it's not "cheaper"
			 * return error if it is
			 */
			if ( $validation_fields['amount'] < $total ) {
				$array['error']  = true;
				$array['reason'] = 'The payment amount is not correct.';

				return $array;
			}
			/**
			 * If errors were not returned, we declare it OK and write it in log
			 */
			$this->addLogEntry( sprintf( 'Check the order\'s amount paid: %s - %s. Payment amount is correct.', $validation_fields['amount'], $total ) );
		}
		/**
		 * Check if the Email address of the receiving end is the same with the one from RSForm!Pro Configuration
		 * return error if it isn't
		 */
		if ( RSFormProHelper::getConfig( 'multipaypal.email' ) !== $validation_fields['receiver_email'] ) {
			$array['error']  = true;
			$array['reason'] = sprintf( 'The email address is not correct - received %s, expected %s', $validation_fields['receiver_email'], RSFormProHelper::getConfig( 'multipaypal.email' ) );

			return $array;
		}
		/**
		 * Write it in log if it passed
		 */
		$this->addLogEntry( sprintf( 'Checking the email address : %s - %s. Email address is correct.', RSFormProHelper::getConfig( 'multipaypal.email' ), $validation_fields['receiver_email'] ) );
		/**
		 * Check the currency of the transaction, see if it matches RSForm!Pro Configuration
		 */
		if ( RSFormProHelper::getConfig( 'payment.currency' ) !== $validation_fields['currency'] ) {
			$array['error']  = true;
			$array['reason'] = sprintf( 'The currency does not match - received %s, expected %s', $validation_fields['currency'], RSFormProHelper::getConfig( 'payment.currency' ) );

			return $array;
		}
		/**
		 * Write it in log if it passed
		 */
		$this->addLogEntry( sprintf( 'Checking currency : %s - %s. Currency is correct.', RSFormProHelper::getConfig( 'payment.currency' ), $validation_fields['currency'] ) );
		/**
		 * If we refunded, write in log the amount.
		 */
		if ( $validation_fields['reason'] == 'refund' ) {
			$this->addLogEntry( sprintf( 'Amount refunded: %s', $validation_fields['amount'] ) );
		}

		return $array;
	}

	/**
	 * @return string
	 */
	protected function _buildPostData() {
		// read the post from PayPal system and add 'cmd'
		$req = 'cmd=_notify-validate';
		//reading raw POST data from input stream. reading pot data from $_POST may cause serialization issues since POST data may contain arrays
		$raw_post_data = file_get_contents( 'php://input' );
		if ( $raw_post_data ) {
			$raw_post_array = explode( '&', $raw_post_data );
			$myPost         = array();
			foreach ( $raw_post_array as $keyval ) {
				$keyval = explode( '=', $keyval, 2 );
				if ( count( $keyval ) == 2 ) {
					$myPost[ $keyval[0] ] = urldecode( $keyval[1] );
				}
			}
		} else {
			$myPost = $_POST;
		}
		foreach ( $myPost as $key => $value ) {
			if ( $key == 'limit' || $key == 'limitstart' || $key == 'option' ) {
				continue;
			}
			$value = urlencode( $value );
			$req   .= "&$key=$value";
		}

		return $req;
	}

	private function loadFormData() {
		$data  = array();
		$db    = JFactory::getDbo();
		$query = $db->getQuery( true )
					->select( '*' )
					->from( $db->qn( '#__rsform_config' ) )
					->where( $db->qn( 'SettingName' ) . ' LIKE ' . $db->q( 'multipaypal.%', false ) );
		if ( $results = $db->setQuery( $query )->loadObjectList() ) {
			foreach ( $results as $result ) {
				$data[ $result->SettingName ] = $result->SettingValue;
			}
		}

		return $data;
	}

	protected function configurationScreen() {
		ob_start();
		JForm::addFormPath( __DIR__ . '/forms' );
		$form = JForm::getInstance( 'plg_system_rsfpmultipaypal.configuration', 'configuration', array( 'control' => 'rsformConfig' ), false, false );
		$form->bind( $this->loadFormData() );
		?>
		<div id="page-paypal" class="form-horizontal">
			<?php
			foreach ( $form->getFieldsets() as $fieldset ) {
				if ( $fields = $form->getFieldset( $fieldset->name ) ) {
					foreach ( $fields as $field ) {
						echo $field->renderField();
					}
				}
			}
			?>
			<div class="alert alert-info"><?php echo JText::_( 'PAYPAL_LANGUAGES_CODES' ) ?></div>
		</div>
		<?php
		$contents = ob_get_contents();
		ob_end_clean();

		return $contents;
	}

	/**
	 * @param $price
	 * @param $amount
	 * @param $type
	 *
	 * @return mixed
	 */
	public function calcTax( $price, $amount, $type ) {
		$price  = (float) $price;
		$amount = (float) $amount;
		switch ( $type ) {
			case false:
				$price = $price + ( ( $price * $amount ) / 100 );
				break;
			case true:
				$price = $price + $amount;
				break;
		}

		return $price;
	}

	protected function _getTotal( $submissionId ) {
		$db    = JFactory::getDbo();
		$query = $db->getQuery( true )
					->select( $db->qn( 'FieldValue' ) )
					->from( $db->qn( '#__rsform_submission_values' ) )
					->where( $db->qn( 'SubmissionId' ) . ' = ' . $db->q( $submissionId ) )
					->where( $db->qn( 'FieldName' ) . ' = ' . $db->q( 'rsfp_Total' ) );
		$db->setQuery( $query );

		return $db->loadResult();
	}

	protected function _getSubmissionId( $formId, $code ) {
		$db    = JFactory::getDbo();
		$query = $db->getQuery( true );
		$query->select( $db->qn( 'SubmissionId' ) )
			  ->from( $db->qn( '#__rsform_submissions', 's' ) )
			  ->where( $db->qn( 's.FormId' ) . ' = ' . $db->q( $formId ) )
			  ->where( 'MD5(CONCAT(' . $db->qn( 's.SubmissionId' ) . ',' . $db->qn( 's.DateSubmitted' ) . ')) = ' . $db->q( $code ) );
		$db->setQuery( $query );
		if ( $SubmissionId = $db->loadResult() ) {
			return $SubmissionId;
		}

		return false;
	}

	protected function _getValidationFields() {
		$jinput            = JFactory::getApplication()->input;
		$validation_fields = array(
				'amount'         => $jinput->post->get( 'mc_gross', '', 'raw' ),
				'currency'       => $jinput->post->get( 'mc_currency', '', 'raw' ),
				'receiver_email' => $jinput->post->get( 'business', '', 'raw' ),
				'formId'         => $jinput->getInt( 'formId', '' ),
				'code'           => $jinput->get( 'code', '' ),
				'reason'         => $jinput->post->get( 'reason_code', '' ),
		);
		if ( empty( $validation_fields['receiver_email'] ) ) {
			$validation_fields['receiver_email'] = $jinput->post->get( 'receiver_email', '', 'raw' );
		}

		return $validation_fields;
	}

	private function number_format( $val ) {
		return number_format( (float) $val, RSFormProHelper::getConfig( 'payment.nodecimals' ), RSFormProHelper::getConfig( 'payment.decimal' ), RSFormProHelper::getConfig( 'payment.thousands' ) );
	}
}

class RSFormProMultiPayPal {
	public $args = array();
	public $url;

	public static function getInstance() {
		static $inst;
		if ( ! $inst ) {
			$inst      = new RSFormProMultiPayPal;
			$inst->url = RSFormProHelper::getConfig( 'multipaypal.test' ) ? 'https://www.paypal.com/cgi-bin/webscr' : 'https://www.sandbox.paypal.com/cgi-bin/webscr';
		}

		return $inst;
	}

	public static function getPaypalusers() {

		$db = JFactory::getDbo();

		$query = $db->getQuery( true )
					->select( 'paypalemail' )
					->from( $db->quoteName( '#__multipaypal_paypal_customer' ) );

		$db->setQuery( $query );

		$results = $db->loadAssocList();
		$uniqueEmails = array_unique(array_column($results, 'paypalemail'));

		return implode("\n", $uniqueEmails);
	}
}

