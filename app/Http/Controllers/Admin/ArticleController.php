<?php

namespace App\Http\Controllers\Admin;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ArticleController extends CommonController
{
    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $data = Article::orderBy('created_at', 'desc')
            ->with('user')
            ->with('tags')
            ->paginate(1);
        return $this->responseJson('OK', $data);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function categories()
    {
        $list = ArticleCategory::all(['id', 'name']);
        return $this->responseJson('OK', $list);
    }

    /**
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse|string
     */
    public function store(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'title' => 'required|string:max:255|min:2',
            'summary' => 'required|string|min:3',
            'content_md' => 'required|string|min:5',
            'content_html' => 'required|string|min:5',
            'recommend' => 'required|int',
            'status' => 'required|int',
        ]);

        if ($validator->fails()) {
            return $this->responseJson('INVALID_REQUEST', $validator->errors()->first());
        }
        $data = [
            'user_id' => \Auth::guard('api')->id(),
            'title' => $request->input('title'),
            'category_id' => $request->input('category_id', 0),
            'summary' => $request->input('summary'),
            'content_md' => trim($request->input('content_md')),
            'content_html' => $request->input('content_html'),
            'recommend' => $request->input('recommend'),
            'status' => $request->input('status'),
        ];
        if ($request->has('cover')) {
            $data['cover'] = preg_replace("/storage(\/.+)/m", '${1}', $request->get('cover'));
        }

        if ($data['status'] == 2) {
            $data['published_at'] = Carbon::now();
        }
        $data['content_length'] = mb_strlen($data['content_html']);
        //dd($request->all());
        $article = Article::create($data);
        //添加标签
        $tags_arr = $request->input('tags');
        if (is_array($tags_arr)) {
            foreach ($tags_arr as $tag_item) {
                $tag[] = Tag::firstOrCreate(['name' => $tag_item])->toArray();
            }
            $tag_ids = array_column($tag, 'id');
            $article->tags()->sync($tag_ids);
        }
        return $this->responseJson('OK', $article);
    }

    /**
     * @param Request $request
     * @return array|\Illuminate\Http\JsonResponse|string
     */
    public function uploadCover(Request $request)
    {
        //提示：如果hasFile失败了，可能是服务器配置问题
        //需要排查以下设置：
        //1，upload_max_filesize
        //2，post_max_size
        //3，以及 upload_tmp_dir 的权限
        //等等关于上传文件的设置。
        if ($request->hasFile('cover')) {
            if ($request->file('cover')->isValid()) {
                $cover_path = $request->file('cover')->storePublicly('upload/article/cover/' . date('Ymd', time()));
                return $this->responseJson('OK', ['cover_path' => $cover_path]);
            } else {
                return responseApi(1, 'error');
            }
        } else {
            return $this->responseJson('INVALID_REQUEST');
        }
    }

    public function searchTag(Request $request)
    {
        $keywords = $request->input('keywords', '');
        $tags = Tag::where('name', 'like', '%' . $keywords . '%')->get(['name'])->toArray();
        return $this->responseJson('OK', $tags);
    }

    public function uploadCoverDel(Request $request)
    {
        if ($cover_path = $request->has('cover_path')) {
            $cover_path = $request->input('cover_path');
            if (Storage::delete($cover_path) === false) {
                return $this->responseJson('DELETE_FAIL');
            }
            return $this->responseJson('OK');

        }
    }

    public function uploadBase64(Request $request)
    {
        $base64_img = trim($request->input('image'));
        $upload_path = '/storage/upload/article/editor/' . date('Ymd', time()) . '/';
        if (!is_dir('.' . $upload_path)) {
            mkdir('.' . $upload_path, 0777, true);
        }
        //preg_match有字符串长度限制
        ini_set('pcre.backtrack_limit', 999999999);
        ini_set('pcre.recursion_limit', 99999);
        ini_set('memory_limit', '64M');
        if (preg_match('/^(data:\s*image\/(.+);base64,).+/', $base64_img, $result)) {
            $type = $result[2];
            if (in_array($type, array('pjpeg', 'jpeg', 'jpg', 'gif', 'bmp', 'png'))) {
                $img_path = $upload_path . str_random(32) . '.' . $type;
                if (file_put_contents('.' . $img_path, base64_decode(str_replace($result[1], '', $base64_img)))) {
                    echo $request->root() . $img_path;
                } else {
                    echo '图片上传失败</br>';
                }
            } else {
                //文件类型错误
                echo '图片上传类型错误';
            }
        } else {
            //文件错误
            echo '文件错误';
        }
    }


    public function edit(Article $article)
    {
        $data = $article->toArray();
        $data['content_html'] = preg_replace("/<img\s+src=['\"](.+)['\"]\s(.*('|\"))>/", '<img src="' . asset('storage') . '${1}" ${2}>', $data['content_html']);
        $data['content_md'] = preg_replace("/^(!\[.*\])\((.*)\)/m", '${1}(' . asset('storage') . '${2})', $data['content_md']);
        $data['cover'] = !empty($data['cover']) ? asset('storage') . '/' . $data['cover'] : '';
        // $data['current_categories'] = $article->articleCategory()->get(['id', 'name']);
        $data['categories'] = ArticleCategory::all(['id', 'name']);
        $tags = $article->tags()->get(['name'])->toArray();
        $data['tags'] = array_values(array_column($tags, 'name'));
        return $this->responseJson('OK', $data);
    }

    /**
     * @param Article $article
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Article $article, Request $request)
    {
        \Debugbar::disable();
        $this->validate($request, [
            'title' => 'required|string:max:255|min:2',
            'summary' => 'required|string|min:3',
            'content_md' => 'required|string|min:5',
            'content_html' => 'required|string|min:5',
            'status' => 'required|int',
        ]);
        if ($request->has('cover')) {
            $article->cover = preg_replace("/storage(\/.+)/m", '${1}', $request->get('cover'));
        }
        $article->title = $request->input('title');
        $article->summary = $request->input('summary');
        //正则去除url地址前缀入库
        $article->cover = preg_replace('/^http.*storage(.*)/m', '${1}', $request->get('cover'));
        $article->content_md = preg_replace('/^!\[.*\]\(http.*\/storage(\/.*)\)/m', '![](${1})', $request->get('content_md'));
        $article->content_html = preg_replace("/<img\s+src=['\"].+storage(.+)['\"]\s(.*('|\"))>/", '<img src="${1}" ${2}>', $request->get('content_html'));
        $article->content_length = mb_strlen($article->content_html);
        $article->recommend = $request->get('recommend');
        $article->category_id = $request->input('category_id', 0);
        $article->status = $request->get('status');
        //添加标签
        $tags_arr = $request->input('tags');

        if (is_array($tags_arr)) {
            foreach ($tags_arr as $tag_item) {
                $tag[] = Tag::firstOrCreate(['name' => $tag_item])->toArray();
            }
            $tag_ids = array_column($tag, 'id');
            $article->tags()->sync($tag_ids);
        }
        if ($request->get('status') == 2) {
            $article->published_at = Carbon::now();
        }
        $article->save();
        return $this->responseJson('OK');
    }

    public function delete(Article $article)
    {
        //权限
        $article->delete();
        return redirect(url('/admin/article'));
    }
}