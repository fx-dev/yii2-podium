<?php

/**
 * Podium Module
 * Yii 2 Forum Module
 */
namespace bizley\podium\controllers;

use bizley\podium\log\Log;
use bizley\podium\models\Content;
use bizley\podium\models\Email;
use bizley\podium\models\Meta;
use bizley\podium\models\Subscription;
use bizley\podium\models\User;
use bizley\podium\Module as PodiumModule;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;
use yii\web\Response;
use yii\web\UploadedFile;

/**
 * Podium Profile controller
 * All actions concerning member profile.
 * 
 * @author Paweł Bizley Brzozowski <pawel@positive.codes>
 * @since 0.1
 */
class ProfileController extends BaseController
{

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class'        => AccessControl::className(),
                'denyCallback' => function ($rule, $action) {
                    return $this->redirect(['account/login']);
                },
                'rules'  => [
                    [
                        'allow'         => false,
                        'matchCallback' => function ($rule, $action) {
                            return !$this->module->getInstalled();
                        },
                        'denyCallback' => function ($rule, $action) {
                            return $this->redirect(['install/run']);
                        }
                    ],
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
        ];
    }
    
    /**
     * Updating the profile details.
     * @return string|Response
     */
    public function actionDetails()
    {
        $model = User::findMe();
        
        if (empty($model)) {
            return $this->redirect(['account/login']);
        }

        $model->scenario         = PodiumModule::getInstance()->userComponent == PodiumModule::USER_INHERIT ? 'accountInherit' : 'account';
        $model->current_password = null;
        $previous_new_email      = $model->new_email;
        
        if ($model->load(Yii::$app->request->post())) {
            if ($model->validate()) {
                if ($model->saveChanges()) {
                    if ($previous_new_email != $model->new_email) {
                        $forum = $this->module->config->get('name');
                        $email = Content::fill(Content::EMAIL_NEW);
                        if ($email !== false && Email::queue(
                                $model->new_email, 
                                str_replace('{forum}', $forum, $email->topic),
                                str_replace('{forum}', $forum, str_replace('{link}', Html::a(
                                        Url::to(['account/new-email', 'token' => $model->email_token], true),
                                        Url::to(['account/new-email', 'token' => $model->email_token], true)
                                    ), $email->content)),
                                !empty($model->id) ? $model->id : null
                            )) {
                            Log::info('New email activation link queued', $model->id, __METHOD__);
                            $this->success(Yii::t('podium/flash', 'Your account has been updated but your new e-mail address is not active yet. Click the activation link that will be sent to your new e-mail address in few minutes.'));
                        }
                        else {
                            Log::error('Error while queuing new email activation link', $model->id, __METHOD__);
                            $this->warning(Yii::t('podium/flash', 'Your account has been updated but your new e-mail address is not active yet. Unfortunately there was some error while sending you the activation link. Contact administrator about this problem.'));
                        }
                    }
                    else {
                        Log::info('Details updated', $model->id, __METHOD__);
                        $this->success(Yii::t('podium/flash', 'Your account has been updated.'));
                    }
                    return $this->refresh();
                }
            }
        }
        
        $model->current_password = null;

        return $this->render('details', ['model' => $model]);
    }
    
    /**
     * Updating the forum details.
     * @return string|Response
     */
    public function actionForum()
    {
        $model = Meta::find()->where(['user_id' => User::loggedId()])->limit(1)->one();
        
        if (empty($model)) {
            $model = new Meta;
        }
        
        if ($model->load(Yii::$app->request->post())) {
            $model->user_id = User::loggedId();
            $uploadAvatar   = false;
            $path           = Yii::getAlias('@webroot/avatars');
            
            $avatar = UploadedFile::getInstance($model, 'image');
            if ($avatar) {
                $folderExists = true;
                if (!file_exists($path)) {
                    if (!FileHelper::createDirectory($path)) {
                        $folderExists = false;
                        Log::error('Error while creating avatars folder', null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while creating the avatars folder. Contact administrator about this problem.'));
                    }
                }
                if ($folderExists) {
                    if (!empty($model->avatar)) {
                        if (!unlink($path . DIRECTORY_SEPARATOR . $model->avatar)) {
                            Log::error('Error while deleting old avatar image', null, __METHOD__);
                        }
                    }
                    $model->avatar = Yii::$app->security->generateRandomString() . '.' . $avatar->getExtension();
                    $uploadAvatar = true;
                }
            }
            
            if ($model->save()) {
                if ($uploadAvatar) {
                    if (!$avatar->saveAs($path . DIRECTORY_SEPARATOR . $model->avatar)) {
                        Log::error('Error while saving avatar image', null, __METHOD__);
                        $this->error(Yii::t('podium/flash', 'Sorry! There was an error while uploading the avatar image. Contact administrator about this problem.'));
                    }
                }
                Log::info('Profile updated', $model->id, __METHOD__);
                $this->success(Yii::t('podium/flash', 'Your profile details have been updated.'));
                return $this->refresh();
            }
        }

        return $this->render('forum', [
                'model' => $model,
                'user'  => User::findMe()
        ]);
    }

    /**
     * Showing the profile card.
     * @return string|Response
     */
    public function actionIndex()
    {
        $model = User::findMe();

        if (empty($model)) {
            if ($this->module->userComponent == PodiumModule::USER_OWN) {
                return $this->redirect(['account/login']);
            }
            return $this->module->goPodium();
        }

        return $this->render('profile', ['model' => $model]);
    }
    
    /**
     * Signing out.
     * @return Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();

        return $this->module->goPodium();
    }
    
    /**
     * Showing the subscriptions.
     * @return string|Response
     */
    public function actionSubscriptions()
    {
        $postData = Yii::$app->request->post();
        if ($postData) {
            if (Subscription::remove(!empty($postData['selection']) ? $postData['selection'] : [])) {
                $this->success(Yii::t('podium/flash', 'Subscription list has been updated.'));
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! There was an error while unsubscribing the thread list.'));
            }
            return $this->refresh();
        }
        
        return $this->render('subscriptions', ['dataProvider' => (new Subscription)->search(Yii::$app->request->get())]);
    }
    
    /**
     * Marking the subscription of given ID.
     * @param integer $id
     * @return Response
     */
    public function actionMark($id = null)
    {
        $model = Subscription::find()->where(['id' => $id, 'user_id' => User::loggedId()])->limit(1)->one();

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Subscription with this ID.'));
        }
        else {
            if ($model->post_seen == Subscription::POST_SEEN) {
                if ($model->unseen()) {
                    $this->success(Yii::t('podium/flash', 'Thread has been marked unseen.'));
                }
                else {
                    Log::error('Error while marking thread', $model->id, __METHOD__);
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while marking the thread.'));
                }
            }
            elseif ($model->post_seen == Subscription::POST_NEW) {
                if ($model->seen()) {
                    $this->success(Yii::t('podium/flash', 'Thread has been marked seen.'));
                }
                else {
                    Log::error('Error while marking thread', $model->id, __METHOD__);
                    $this->error(Yii::t('podium/flash', 'Sorry! There was some error while marking the thread.'));
                }
            }
            else {
                $this->error(Yii::t('podium/flash', 'Sorry! Subscription has got the wrong status.'));
            }
        }

        return $this->redirect(['profile/subscriptions']);
    }
    
    /**
     * Deleting the subscription of given ID.
     * @param integer $id
     * @return Response
     */
    public function actionDelete($id = null)
    {
        $model = Subscription::find()->where(['id' => (int)$id, 'user_id' => User::loggedId()])->limit(1)->one();

        if (empty($model)) {
            $this->error(Yii::t('podium/flash', 'Sorry! We can not find Subscription with this ID.'));
        }
        else {
            if ($model->delete()) {
                $this->module->cache->deleteElement('user.subscriptions', User::loggedId());
                $this->success(Yii::t('podium/flash', 'Thread has been unsubscribed.'));
            }
            else {
                Log::error('Error while deleting subscription', $model->id, __METHOD__);
                $this->error(Yii::t('podium/flash', 'Sorry! There was some error while deleting the subscription.'));
            }
        }

        return $this->redirect(['profile/subscriptions']);
    }
    
    /**
     * Subscribing the thread of given ID.
     * @param integer $id
     * @return Response
     */
    public function actionAdd($id = null)
    {
        if (!Yii::$app->request->isAjax) {
            return $this->redirect(['default/index']);
        }
            
        $data = [
            'error' => 1,
            'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . 
                    Yii::t('podium/view', 'Error while adding this subscription!'), ['class' => 'text-danger']),
        ];

        if (Yii::$app->user->isGuest) {
            $data['msg'] = Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . 
                    Yii::t('podium/view', 'Please sign in to subscribe to this thread'), ['class' => 'text-info']);
        }
            
        if (is_numeric($id) && $id > 0) {
            $subscription = Subscription::find()->where(['thread_id' => $id, 'user_id' => User::loggedId()])->limit(1)->one();

            if (!empty($subscription)) {
                $data['msg'] = Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-warning-sign']) . ' ' . 
                        Yii::t('podium/view', 'You are already subscribed to this thread.'), ['class' => 'text-info']);
            }
            else {
                if (Subscription::add((int)$id)) {
                    $data = [
                        'error' => 0,
                        'msg'   => Html::tag('span', Html::tag('span', '', ['class' => 'glyphicon glyphicon-ok-circle']) . ' ' . 
                                Yii::t('podium/view', 'You have subscribed to this thread!'), ['class' => 'text-success']),
                    ];
                }
            }
        }

        return Json::encode($data);
    }
}
