<?php
namespace app\index\controller;

use app\index\controller\Common;
use think\Cache;

class Share extends Common {

    protected $model;

    public function __construct()
    {
        parent::__construct();
        $this->model = model('Share');
    }

    public function read($id) {
        $data = $this->model->getShare($id);
        if (!$data) {
            return $this->error('这条数据不存在或已被删除');
        }
        $this->assign('data', $data);

        // 该用户其他热门分享
        $user_share = $this->model
            ->where('user_id', $data->user_id)
            ->order('score desc')
            ->limit(4)
            ->select();
        $this->assign('user_share', $user_share);

        // 读取标签
        $tags = $this->model
            ->find($id)->tags()
            ->where('status>0')
            ->select();
        $this->assign('tags', $tags);

        // 浏览量自增 (延时 60s)
        $this->model->where('share_id', $id)->setInc('click', 1, 60);

        // 获取当前评分
        $score = $this->checkScored($id);
        $this->assign('score', $score);

        // 获取评论
        $comments = $this->model
            ->find($id)->comments()
            ->select();
        $this->assign('comments', $comments);

        return view('detail');
    }

    public function create()
    {
        $category_list = model('Cate')->order('sort desc')->column('cate_name', 'cate_id');
        return $this->fetch('addShare', ['category_list' => $category_list]);
    }

    /**
     * 添加分享的处理方法
     * 请注意 文件移动之前是存储在 php 临时目录下的, 请确保该目录和 'public/uploads/' 的权限为 777.
     * 生成缩略图的最大尺寸是 480x960px. 预览图最大尺寸是 1440x900px. 原图不做处理
     * @author 杨栋森 mutoe@foxmail.com at 2016-12-26
     */
    public function save()
    {
        // 文件合法性验证
        $file = request()->file('image');
        $mine = ['image/jpeg', 'image/gif', 'image/png', 'image/jpg'];
        $info = $file->validate(['size' => 8*1024*1024, 'type' => $mine]);
        if (!$info) {
            return $this->error($info->getError());
        }

        // 初始化文件数据
        $savepath = 'uploads/'. date('Ym', time()). '/';
        $public_path = ROOT_PATH. 'public/'. $savepath;
        $image = \think\Image::open($file);
        $height = $image->height();
        $width = $image->width();

        // 初始化模型
        $savedata = input('post.');
        $savedata['width'] = $width;
        $savedata['height'] = $height;
        $savedata['savepath'] = $savepath;
        $result = $this->model->data($savedata, true);

        // 数据合法性验证
        $validate = \think\Loader::validate('Share');
        if(!$validate->check($result)) {
            $this->error($validate->getError());
        }

        // 原图增加前缀 'o_'
        $info = $file->rule('uniqid')->move(ROOT_PATH. 'public/'. $savepath);
        $savename = $info->getFilename();
        copy($public_path. $savename, $public_path. 'o_'. $savename);

        // 生成缩略图
        $image->thumb(480, 9999);
        // 长图裁取顶部 960px
        if ($height > 3 * $width) {
            $image->crop(480, 960);
        }
        $image->save($public_path. 't_'. $savename);

        // 生成预览图
        $image = \think\Image::open($file);
        $image->thumb(1440, 900)->save($public_path. $savename);

        // 更新保存名称
        $this->model->savename = $savename;

        // 准备保存数据
        $savedata = $this->model->toArray();
        $tags = explode(',', $savedata['tags']);        // 处理标签字段用
        unset($savedata['cate_id'], $savedata['tags']); // 保存 share_profile 表数据用

        // 保存 share 表数据
        $result = $this->model->allowField(true)->save();
        if (!$result) {
            return $this->error($this->model->getError());
        }

        // 保存 share_profile 表数据
        $share_id = $this->model->share_id;
        $this->model->find($share_id)->profile()->save($savedata);

        // 数据创建成功后删除临时文件
        @unlink($info->getInfo('tmp_name'));

        // 处理标签字段
        $result = model('Tag')->handleTags($tags, $share_id, auth_status('user_id'));
        if (!$result) {
            return $this->error('分享添加成功, 但标签似乎出了点问题', '/share/'.$share_id);
        }

        return $this->redirect('/share/'. $share_id);
    }

    /**
     * 刷新缓存
     * 目前可刷新 "分类点击量"
     * @author 杨栋森 mutoe@foxmail.com at 2016-12-28
     */
    public function refresh_cache()
    {
        $cate = model('Cate');
        $data = $cate->select();
        $result = array_fill(1, $cate->max('cate_id'), 0);
        foreach ($data as $key => $value) {
            $sharelist = $this->model->where(['cate_id'=>$value['cate_id']])->column('click');
            $count = 0;
            foreach ($sharelist as $value1) {
                $count += $value1;
            }
            $result[$value['cate_id']] = $count;
        }
        Cache::set('cate_click_list', $result);
    }

    /**
     * 收藏
     * TODO: 实现方法 将作品加入到 'xx的收藏' 相册 这是一个特殊的相册
     * @author 杨栋森 mutoe@foxmail.com at 2017-03-30
     *
     * @param  [type] $share_id [description]
     * @return [type]           [description]
     */
    public function star($share_id)
    {
        return $this->error('stared');
    }

    /**
     * 作品评分
     * 每个用户对每个作品每天能评分一次, 评分后刷新页面会显示今天的评分
     * @author 杨栋森 mutoe@foxmail.com at 2017-03-31
     */
    public function score($share_id)
    {
        // 过滤数据
        $score = floor(input('post.score'));
        if ($score > 10 || $score <= 0) {
            return $this->error('非法请求');
        }
        $share_data = $this->model->where('status>0')->find($share_id);
        if (!$share_data) {
            return $this->error('非法请求!');
        }
        if ($share_data->user_id == auth_status('user_id')) {
            return $this->error('你不能给自己的分享评分 !');
        }

        // 数据操作
        $share_score = model('ShareScore');
        $user_id = auth_status('user_id');
        $find = $share_score->find($user_id);
        $date = date('ymd', time());

        if (!$find) {
            // 创建新纪录
            $data = [];
            $share_score->post_date = $date;
            $post_date = $date;
        } else {
            // 分析投票记录
            $data = obj2arr(json_decode($find->data));
            if ($find->post_date == $date) {
                // 当天存在投票记录
                if (isset($data[$share_id])) {
                    $msg = "你的评分: ".$data[$share_id]."分! (你只可以一天评分一次)";
                    return $this->error($msg);
                }
            } else {
                // 如果不是今天则刷新数据
                $data = [];
                $share_score->post_date = $date;
            }
        }

        // 更新评分
        $old_score = $this->model->find($share_id)->score;
        $this->model->where('share_id', $share_id)
            ->inc('score_count')->inc('score', $score)->update();

        // 保存数据
        $data[$share_id] = $score;
        $share_score->data = json_encode($data);
        $share_score->user_id = $user_id;
        $result = $share_score->isUpdate($find)->save();

        // 发送通知
        $create_time = $share_data->profile->create_time;
        $this->setScoreNotice($share_data->user_id, [$share_id, $create_time], [$old_score, $score]);

        return $this->success();
    }

    /**
     * 检查当前登陆用户是否对某作品已经评分
     * @author 杨栋森 mutoe@foxmail.com at 2017-03-31
     *
     * @param  $share_id
     * @return 返回评分或 false
     */
    private function checkScored($share_id)
    {
        // 查询评分数据
        $user_id = auth_status('user_id');
        $find = model('ShareScore')->find($user_id);
        if (!$find) return false;

        // 如果不是当天的数据
        if ($find->post_date != date('ymd', time())) return false;

        // 解析数据
        $data = obj2arr(json_decode($find->data));
        return isset($data[$share_id]) ? $data[$share_id] : false;
    }

    /**
     * 发送评分通知
     * @author 杨栋森 mutoe@foxmail.com at 2017-04-07
     *
     * @param  integer  $user_id     接收方 user_id
     * @param  array    $share       被评分投稿 [share_id, create_time]
     * @param  array    $score_list  评分 [原得分, 得分]
     */
    private function setScoreNotice($user_id, $share, $score_list)
    {
        // 获取原评分/得分/现评分
        list($old_score, $score) = $score_list;
        $new_score = $old_score + $score;

        // 获取历史通知
        $notice = model('Notice');
        $notice_data = $notice->where(['type' => 'score', 'user_id' => $user_id])->find();

        // 如果没有历史通知并且现评分小于 10 分则不发送通知
        if (!$notice_data && $new_score < 10) return null;

        // 修改或新增标记
        $notice_id = $notice_data ? $notice_data->notice_id : 0;

        // 舍去零头
        $timer = 1;
        while($new_score >= 10) {
            $timer *= 10;
            $new_score /= 10;
        }
        while($old_score >= 10) {
            $old_score /= 10;
        }
        // 若舍去零头后新评分等于原评分则不发送通知
        if (floor($old_score) == ($new_score = floor($new_score))) return null;

        // 有进位 更新通知
        $extra = [
            'share_id'      => $share[0],
            'create_time'   => $share[1],
            'score'         => $new_score * $timer
        ];
        return $notice->setNotice('score', $user_id, $extra, $notice_id);
    }

}
