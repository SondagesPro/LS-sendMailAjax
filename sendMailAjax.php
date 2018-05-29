<?php
/**
 * sendMailAjax Plugin for LimeSurvey
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2015-2016 Denis Chenu <http://sondages.pro>
 * @license AGPL v3
 * @version 1.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 */
class sendMailAjax extends PluginBase {
  protected $storage = 'DbStorage';

  static protected $description = 'Send email one by one with ajax.';
  static protected $name = 'sendMailAjax';

    public function init() {
        $this->subscribe('newDirectRequest');
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeControllerAction');
    }

    /**
    * Keep the survey id for firect request function
    * @access private
    * @var integer
    */
    private $iSurveyId;

    /**
    * newDirectRequest redirecting to call function
    */
    public function newDirectRequest()
    {
        $oEvent = $this->event;
        $sFunction=$oEvent->get('function');
        if ($oEvent->get('target') != get_class())
            return;

        $this->iSurveyId=$iSurveyId=$this->api->getRequest()->getParam('surveyid');
        $oSurvey=Survey::model()->findByPK($iSurveyId);
        if(!$oSurvey)
            throw new CHttpException(404, gt("The survey does not seem to exist."));
        if(!Permission::model()->hasSurveyPermission($iSurveyId, 'tokens', 'update'))
            throw new CHttpException(401, gt("You do not have sufficient rights to access this page."));
        if(!tableExists('{{tokens_' . $iSurveyId . '}}'))
            throw new CHttpException(404, gt("Token table don't exist."));
        if($oSurvey->active!="Y")
            throw new CHttpException(404, gt("The survey seem’s inactive."));

        $sType=$this->api->getRequest()->getParam('type');
        if(!in_array($sType,array('remind','invite')))
            throw new CHttpException(500, gt("Unknow type"));
        switch ($sFunction)
        {
            case "confirm":
                $this->actionConfirm($sType);
                break;
            case "send":
                $this->actionSend($sType);
                break;
            default:
                throw new CHttpException(500, gt("Unknow action"));
        }
    }

    /**
    * Show the settings and the link
    */
    public function beforeSurveySettings()
    {
        $oEvent = $this->event;
        $iSurveyId=$oEvent->get('survey');
        $oSurvey=Survey::model()->findByPk($iSurveyId);
        if(tableExists('{{tokens_' . $iSurveyId . '}}') && Permission::model()->hasSurveyPermission($iSurveyId, 'tokens', 'update') && $oSurvey->active=="Y") {

            $oEvent->set("surveysettings.{$this->id}", array(
              'name' => get_class($this),
              'settings' => array(
                'mindaydelay'=>array(
                  'type'=>'int',// float is not fixed in 2.05
                  'label'=>'Minimum day after last email (invite or remind).',
                  'current' => $this->get('mindaydelay', 'Survey', $iSurveyId,'1'),
                    'htmlOptions'=>array(
                      'class'=>'form-control'
                    ),
                ),
                'maxremind'=>array(
                  'type'=>'int',
                  'label'=>'Don’t send remind if user have already receive X reminder',
                  'current' => $this->get('maxremind', 'Survey', $iSurveyId,''),
                    'htmlOptions'=>array(
                      'class'=>'form-control'
                    ),
                ),
                'launchinvite'=>array(
                  'type'=>'link',
                  'label'=>gt('Send email invitation'),
                  'htmlOptions'=>array(
                    'title'=>gt('Send email invitation'),
                  ),
                  'class'=>'popup-sendmailajax btn-default',
                  'link'=>$this->api->createUrl('plugins/direct', array('plugin' => get_class(),'surveyid'=>$iSurveyId,'function' => 'confirm','type'=>'invite')),
                ),
                'launchremind'=>array(
                  'type'=>'link',
                  'label'=>gt('Send email reminder'),
                  'class'=>'popup-sendmailajax btn-default',
                  'htmlOptions'=>array(
                    'title'=>gt('Send email reminder'),
                  ),
                  'link'=>$this->api->createUrl('plugins/direct', array('plugin' => get_class(),'surveyid'=>$iSurveyId,'function' => 'confirm','type'=>'remind'))
                ),
              )
            ));
        }
    }

    /**
    * Save the settings
    */
    public function newSurveySettings()
    {
        $event = $this->event;
        $aSettings=$event->get('settings');
        $aSettings['mindaydelay']=(isset($aSettings['mindaydelay']) && intval($aSettings['mindaydelay'])>=0) ? intval($aSettings['mindaydelay']) : 1;
        $aSettings['maxremind']=(isset($aSettings['maxremind']) && intval($aSettings['maxremind'])>=0) ? intval($aSettings['maxremind']) : 0;
        foreach ($aSettings as $name => $value)
        {
            $this->set($name, $value, 'Survey', $event->get('survey'));
        }
    }

    /**
    * Construct and render the confirm box
    * @param string $sType : email type to send
    */
    private function actionConfirm($sType)
    {
        $oEvent=$this->event;
        Yii::app()->controller->layout='bare'; // bare don't have any HTML
        Yii::setPathOfAlias('sendmailajaxViews', dirname(__FILE__) . '/views/');

        $oCriteria=$this->getBaseCriteria($sType);

        $renderData['count']=TokenDynamic::model($this->iSurveyId)->count($oCriteria);
        switch ($sType)
        {
            case 'invite':
                $renderData['confirminfo']=gt('Send email invitation');
                $renderData['buttonText']=gt('Send Invitations');
                break;
            case 'remind':
                $renderData['confirminfo']=gt('Send email reminder');
                $renderData['confirminfo'].=CHtml::tag('ul',array('class'=>'alert alert-info'),"",false);
                $renderData['confirminfo'].=CHtml::tag('li',array(),sprintf(gt('Minimum day after last email : %s'),$this->get('mindaydelay', 'Survey', $this->iSurveyId,'1')));
                $renderData['confirminfo'].=CHtml::tag('li',array(),sprintf(gt('Do not send remind if user have already receive %s reminder'),$this->get('maxremind', 'Survey', $this->iSurveyId,'0')));
                $renderData['confirminfo'].=CHtml::closeTag('ul');
                $renderData['buttonText']=gt('Send Reminders');
                break;
            default:
                throw new CHttpException(404, gt("Unknow action"));
        }
        $renderData['sendUrl']=$this->api->createUrl('plugins/direct', array('plugin' => get_class(),'surveyid'=>$this->iSurveyId,'function' => 'send','type'=>$sType));
        $content=Yii::app()->controller->renderPartial("sendmailajaxViews.popup",$renderData);
        echo $content;
    }

    /**
    * Send action, and construct the json
    * @param string $sType : email type to send
    */
    private function actionSend($sType)
    {
        $iNextToken=$this->api->getRequest()->getParam('tokenid');
        $oSendCriteria=$this->getBaseCriteria($sType);

        $oNextCriteria=$this->getBaseCriteria($sType);
        $oNextCriteria->order='tid ASC';

        $aData=array(
            'status'=>'',
            'message'=>'',
            'next'=>'',
        );
        if($iNextToken)
        {
            $oSendCriteria->compare('tid',$iNextToken);
            $oNextCriteria->compare('tid',">".$iNextToken);
        }
        else
        {
            $oSendCriteria->order='tid ASC';
        }

        $oToken=TokenDynamic::model($this->iSurveyId)->find($oSendCriteria);
        if($oToken)
        {
            $aData=array_replace($aData,$this->sendMail($oToken,$sType));

            if(!$iNextToken)
                $oNextCriteria->compare('tid',">".$oToken->tid);
        }
        else
        {
            $aData['message']="No token with this id";
        }
        $oNextToken=TokenDynamic::model($this->iSurveyId)->find($oNextCriteria);
        if($oNextToken)
            $aData['next']=$oNextToken->tid;
        $this->renderJson($aData);
    }

    /**
    * Render a application/json
    * @param array $aData : array to render
    */
    private function renderJson($aData)
    {
            Yii::import('application.helpers.viewHelper');
            viewHelper::disableHtmlLogging();
            header('Content-type: application/json; charset=utf-8');
            echo json_encode($aData);
            Yii::app()->end();
    }

    /**
    * Construct the criteria needed for all find
    * @param string $sType : email type to send
    * @return object criteria (@see http://www.yiiframework.com/doc/api/1.1/CDbCriteria ).
    */
    private function getBaseCriteria($sType)
    {
        $oCriteria= new CDbCriteria();
        $oCriteria->condition="email IS NOT NULL and email != '' ";
        $oCriteria->addCondition("token IS NOT NULL and token != ''");
        $oCriteria->addCondition("completed ='N' OR completed='' OR completed IS NULL");
        $oCriteria->compare('emailstatus',"OK");

        $dToday=dateShift(date("Y-m-d H:i:s"),"Y-m-d H:i:s", Yii::app()->getConfig("timeadjust"));
        $oCriteria->addCondition("validfrom < :validfrom OR validfrom IS NULL");
        $oCriteria->addCondition("validuntil > :validuntil OR validuntil IS NULL");

        $oCriteria->compare('usesleft',">0");
        $oCriteria->addCondition("blacklisted IS NULL OR blacklisted='' ");

        switch ($sType)
        {
            case 'invite':
                $oCriteria->addCondition("sent ='N' OR sent='' OR sent IS NULL");
                break;
            case 'remind':
                $oCriteria->addCondition("sent !='N' AND sent!='' AND sent IS NOT NULL");
                $iMinDayDelay=intval($this->get('mindaydelay', 'Survey', $this->iSurveyId,'1'));
                $iMaxRemind=intval($this->get('maxremind', 'Survey', $this->iSurveyId,null));
                if($iMinDayDelay>0)
                {
                    $dDateCompare = dateShift(
                        date("Y-m-d H:i:s", time() - 86400 * $iMinDayDelay),
                        "Y-m-d H:i:s", Yii::app()->getConfig("timeadjust")
                    );
                    $oCriteria->addCondition("(remindersent ='N' AND sent < :dateSent) OR remindersent < :dateReminder");
                    $oCriteria->params=array_merge($oCriteria->params,
                        array(
                            ':dateSent'=>$dDateCompare,
                            ':dateReminder'=>$dDateCompare,
                        )
                    );
                }
                if($iMaxRemind>0)
                {
                    $oCriteria->addCondition("remindercount < :remindercount");
                    $oCriteria->params=array_merge($oCriteria->params,
                        array(
                            ':remindercount'=>$iMaxRemind,
                        )
                    );
                }
                break;
        }
        $oCriteria->params=array_merge($oCriteria->params,
            array(
                ':validfrom'=>$dToday,
                ':validuntil'=>$dToday,
            )
        );

        return $oCriteria;
    }

    /**
    * Send an email to this token
    * @param object $oToken : the token
    * @param string $sType : email type to send
    * @return array (status and message)
    */
    private function sendMail($oToken,$sType)
    {
        $returnData=array(
            'status'=>'',
            'message'=>'',
        );
        $oSurvey=Survey::model()->findByPk($this->iSurveyId);
        Yii::app()->setConfig('surveyID',$this->iSurveyId);
        $aSurveyLangs = $oSurvey->getAllLanguages();
        $aTokenFields = getTokenFieldsAndNames($this->iSurveyId, true);
        $bHtml = $oSurvey->htmlemail=='Y';

        if(in_array($oToken->language,$aSurveyLangs))
            $sLang=$oToken->language;
        else
            $sLang=$oSurvey->language;

        $oSurveyLanguage=SurveyLanguageSetting::model()->find("surveyls_survey_id = :sid AND surveyls_language = :language",array(':sid'=>$this->iSurveyId,':language'=>$sLang));
        switch ($sType)
        {
            case 'invite':
                $sSubject=$oSurveyLanguage->surveyls_email_invite_subj;
                $sMessage=$oSurveyLanguage->surveyls_email_invite;
                $template='invitation';
                break;
            case 'remind':
                $sSubject=$oSurveyLanguage->surveyls_email_remind_subj;
                $sMessage=$oSurveyLanguage->surveyls_email_remind;
                $template='reminder';
                break;
            default:
                throw new CHttpException(500);
        }
        $sSubject=preg_replace("/{TOKEN:([A-Z0-9_]+)}/","{"."$1"."}",$sSubject);
        $sMessage=preg_replace("/{TOKEN:([A-Z0-9_]+)}/","{"."$1"."}",$sMessage);
        if ($bHtml)
            $sMessage = html_entity_decode($sMessage, ENT_QUOTES, Yii::app()->getConfig("emailcharset"));

        $to = array();
        $aEmailaddresses = explode(';', $oToken->email);
        foreach ($aEmailaddresses as $sEmailaddress)
        {
            $to[] = "{$oToken->firstname} {$oToken->lastname} <{$sEmailaddress}>";
        }
        $from = "{$oSurvey->admin} <{$oSurvey->adminemail}>";

        $aReplace=array();
        foreach($oToken->attributes as $key=>$value)
        {
            $aReplace[strtoupper($key)]=$value;
        }
        $aReplace["ADMINNAME"] = $oSurvey->admin;
        $aReplace["ADMINEMAIL"] = $oSurvey->adminemail;
        $aReplace["SURVEYNAME"] = $oSurveyLanguage->surveyls_title;
        $aReplace["SURVEYDESCRIPTION"] = $oSurveyLanguage->surveyls_description;
        $aReplace["EXPIRY"] = $oSurvey->expires;
        $aReplace["OPTOUTURL"] = Yii::app()->getController()
                                           ->createAbsoluteUrl("/optout/tokens",array('langcode'=> $oToken->language,'surveyid'=>$this->iSurveyId,'token'=>$oToken->token));
        $aReplace["OPTINURL"] = Yii::app()->getController()
                                          ->createAbsoluteUrl("/optin/tokens",array('langcode'=> $oToken->language,'surveyid'=>$this->iSurveyId,'token'=>$oToken->token));
        $aReplace["SURVEYURL"] = Yii::app()->getController()
                                           ->createAbsoluteUrl("/survey/index",array('sid'=>$this->iSurveyId,'token'=>$oToken->token,'lang'=>$oToken->language));
        $aBareBone=array();
        foreach(array('OPTOUT', 'OPTIN', 'SURVEY') as $key)
        {
            $url = $aReplace["{$key}URL"];
            if ($bHtml) $aReplace["{$key}URL"] = "<a href='{$url}'>" . htmlspecialchars($url) . '</a>';
            $aBareBone["@@{$key}URL@@"]=$url;
        }
        $aRelevantAttachments = array();
        if(!empty($oSurveyLanguage->attachments))
        {
            $aTemplateAttachments = unserialize($oSurveyLanguage->attachments);
            switch($sType)
            {
                case 'invite':
                    $aAttachments=isset($aTemplateAttachments['invitation']) ? $aTemplateAttachments['invitation'] : null;
                    break;
                case 'remind':
                    $aAttachments=isset($aTemplateAttachments['reminder']) ? $aTemplateAttachments['reminder'] : null;
                    break;
                default:
                    break;
            }
            if(!empty($aAttachments))
            {
                LimeExpressionManager::singleton()->loadTokenInformation($this->iSurveyId, $oToken->token);
                foreach($aAttachments as $aAttachment)
                {
                    if (LimeExpressionManager::singleton()->ProcessRelevance($aAttachment['relevance']))
                    {
                        $aRelevantAttachments[] = $aAttachment['url'];
                    }
                }
                // Why not use LimeExpressionManager::ProcessString($sSubject, NULL, $aReplace, false, 2, 1, false, false, true); ?
            }
        }
        $sSubject=LimeExpressionManager::ProcessString($sSubject, NULL, $aReplace, false, 2, 1, false, false, true);
        $sMessage=LimeExpressionManager::ProcessString($sMessage, NULL, $aReplace, false, 2, 1, false, false, true);
        $sSubject=str_replace (array_keys($aBareBone),$aBareBone,$sSubject);
        $sMessage=str_replace (array_keys($aBareBone),$aBareBone,$sMessage);

        $aCustomHeaders = array(
            '1' => "X-surveyid: " . $this->iSurveyId,
            '2' => "X-tokenid: " . $oToken->token
        );
        global $maildebug;
        $event = new PluginEvent('beforeTokenEmail');
        $event->set('survey', $this->iSurveyId);
        $event->set('type', $template);
        $event->set('model', $sType);
        $event->set('subject', $sSubject);
        $event->set('to', $to);
        $event->set('body', $sMessage);
        $event->set('from', $from);
        $event->set('bounce', getBounceEmail($this->iSurveyId));
        $event->set('token', $oToken->attributes);
        App()->getPluginManager()->dispatchEvent($event);
        $sSubject = $event->get('subject');
        $sMessage = $event->get('body');
        $to = $event->get('to');
        $from = $event->get('from');
        if ($event->get('send', true) == false)
        {
            // This is some ancient global used for error reporting instead of a return value from the actual mail function..
            $maildebug = $event->get('error', $maildebug);
            $success = $event->get('error') == null;
        }
        else
        {
            $success = SendEmailMessage($sMessage, $sSubject, $to, $from, Yii::app()->getConfig("sitename"), $bHtml, getBounceEmail($this->iSurveyId), $aRelevantAttachments, $aCustomHeaders);
        }
        if ($success)
        {
            $returnData['status']='success';
            switch($sType)
            {
                case 'invite':
                    $returnData['message']=gT("Invitation sent to:")." {$oToken->tid} : {$oToken->firstname} {$oToken->lastname} &lt{$oToken->email}&gt";
                    $oToken->sent = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig("timeadjust"));
                    break;
                case 'remind':
                    $returnData['message']=gT("Reminder sent to:")." {$oToken->tid} : {$oToken->firstname} {$oToken->lastname} &lt{$oToken->email} &gt";
                    $oToken->remindersent = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig("timeadjust"));
                    $oToken->remindercount++;
                    break;
                default:
            }

            $oToken->save();
        }
        else
        {
            $returnData['status']='error';
            $returnData['message']=LimeExpressionManager::ProcessString(gT("Email to {FIRSTNAME} {LASTNAME} ({EMAIL}) failed. Error Message:") . "<pre>" . $maildebug . "</pre>", NULL, $aReplace, false, 2, 1, false, false, true);
        }
        return $returnData;
    }

    /**
     * @see event beforeControllerAction
     * Adding needed package
     */
    public function beforeControllerAction()
    {
        if(!$this->getEvent()->get('controller') == 'admin' || !$this->getEvent()->get('action') == 'survey') {
            return;
        }
        /* Quit if is done */
        if(array_key_exists(get_class($this),Yii::app()->getClientScript()->packages)) {
            return;
        }
        /* Add package if not exist (allow to use another one in config) */
        if(!Yii::app()->clientScript->hasPackage(get_class($this))) {
            Yii::setPathOfAlias(get_class($this),dirname(__FILE__));
            Yii::app()->clientScript->addPackage(get_class($this), array(
                'basePath'    => get_class($this).'.assets',
                'css'         => array(get_class($this).'.css'),
                'js'          => array(get_class($this).'.js'),
                'depends'      =>array( 'adminbasicjs'),
            ));
        }
        /* Registering the package */
        Yii::app()->getClientScript()->registerPackage(get_class($this));
    }
}
