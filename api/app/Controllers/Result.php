<?php namespace App\Controllers;
use CodeIgniter\HTTP\RequestInterface;

class Result extends BaseController
{
	 
	 
    public function __construct()
    {
        
    }

    // Normalize to UTF-8 (handles legacy CSV imports with ISO-8859-1/Windows-1252)
    private function toUtf8($s){
        if($s === null){ return ''; }
        if(function_exists('mb_detect_encoding') && mb_detect_encoding($s, 'UTF-8', true)){
            return $s;
        }
        foreach(['ISO-8859-1','Windows-1252'] as $enc){
            $c = @iconv($enc, 'UTF-8//IGNORE', $s);
            if($c !== false){ return $c; }
        }
        if(function_exists('utf8_encode')){ return utf8_encode($s); }
        return $s;
    }

    // Recompute and persist per-question metrics from recorded answers
    private function recomputeMetrics($rid){
        $db = \Config\Database::connect('writeDB');
        $q = $db->query("SELECT r.*, q.correct_score, q.incorrect_score, q.min_pass_percentage FROM sq_result r JOIN sq_quiz q ON q.id=r.quid WHERE r.id='$rid'");
        if(count($q->getResultArray()) == 0){ return null; }
        $row = $q->getRowArray();
        $assigned_qids = array_values(array_filter(array_map('trim', explode(',', (string)$row['assigned_qids'])), 'strlen'));
        $qCount = count($assigned_qids);
        if($qCount==0){ return $row; }

        $cs_list = explode(',', (string)$row['correct_score']);
        $ics_list = explode(',', (string)$row['incorrect_score']);
        $attempted = array_fill(0, $qCount, 0);
        $ind_score = array_fill(0, $qCount, 0);
        $max_scores = array_fill(0, $qCount, 0);
        $obt = 0.0;

        foreach($assigned_qids as $i => $qid){
            $qid = trim($qid);
            if($qid===''){ continue; }
            // Sum distinct selected options to avoid double-counting from repeated saves
            $res = $db->query("SELECT ROUND(SUM(o.score),2) AS s, COUNT(1) AS cnt FROM (SELECT DISTINCT user_response FROM sq_answer WHERE rid='$rid' AND question_id='$qid') a JOIN sq_option o ON o.id=a.user_response");
            $sum = 0.0; $cnt = 0;
            if(count($res->getResultArray())>0){
                $rr = $res->getRowArray();
                $cnt = (int)$rr['cnt'];
                $sum = ($rr['s']===null? 0.0 : (float)$rr['s']);
            }
            $attempted[$i] = $cnt>0 ? 1 : 0;
            $cscore = (count($cs_list)>1) ? (float)$cs_list[$i] : (float)$cs_list[0];
            $icscore = (count($ics_list)>1) ? (float)$ics_list[$i] : (float)$ics_list[0];
            $max_scores[$i] = $cscore;
            if($cnt>0){
                if($sum == 1.0){ $ind_score[$i] = $cscore; $obt += $cscore; }
                else { $ind_score[$i] = $icscore; }
            }else{
                $ind_score[$i] = 0.0;
            }
        }

        $attempted_s = implode(',', $attempted);
        $ind_score_s = implode(',', $ind_score);
        $max_total = array_sum($max_scores);
        // Percentage relative to attempted questions (if any), else out of total
        $attempted_max = 0.0;
        foreach($attempted as $i => $a){ if($a){ $attempted_max += $max_scores[$i]; } }
        $base = $attempted_max > 0 ? $attempted_max : $max_total;
        $perc = $base>0 ? round(($obt/$base)*100,2) : 0.0;
        $status = ($perc >= (float)$row['min_pass_percentage']) ? 'Pass' : 'Fail';

        $db->query("UPDATE sq_result SET attempted_questions='$attempted_s', ind_score='$ind_score_s', obtained_score='$obt', obtained_percentage='$perc', result_status='$status' WHERE id='$rid'");

        // return refreshed row with joins used by view()
        $q2 = $db->query("select sq_result.*, sq_quiz.quiz_name, sq_quiz.duration, sq_quiz.correct_score, sq_quiz.incorrect_score, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where sq_result.id='$rid'");
        return $q2->getRowArray();
    }

 
 	public function getList(){
		$db1 = \Config\Database::connect('writeDB');
		$db2 = \Config\Database::connect('readDB');
		// check required post data
		$json_arr=array();
		$user_token = $this->request->getVar('user_token');
		$search = $this->request->getVar('search');
		if($search==null){ $search='';	}
		$id = $this->request->getVar('id');
		if($id==null){ $id='';	}
		$limit = $this->request->getVar('limit');
		$maxRowsPerPage = $this->request->getVar('maxRowsPerPage');
		if($limit==null){ $limit=0;	}
		if($maxRowsPerPage==null){ $maxRowsPerPage=30;	}
		
		$validateToken=$this->validateToken();
		if($validateToken != "success"){ return $validateToken;  }
		$authAccess=$this->authAccess('resultList');
		if($authAccess != "success"){ return $authAccess; } 
		if($search==''){
		$where="";
			if($id != ''){
					$where=" and sq_result.id='".$id."' ";
			}

		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open'  $where order by sq_result.id desc limit $limit, $maxRowsPerPage");
		}else{
		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open'  and (sq_quiz.quiz_name like '%$search%' or sq_result.id like '%$search%'  or sq_user.username like '%$search%'   or sq_user.email like '%$search%'     or sq_user.full_name like '%$search%'  ) order by sq_result.id desc limit $limit, $maxRowsPerPage");
			
		}
		
		$result=$query->getResultArray();
		if(count($query->getResultArray()) >= 1){
			$json_arr['status']="success"; 	$json_arr['message']="";
			$json_arr['data']=$result;
		}else{
			$json_arr['status']="failed"; 	$json_arr['message']="No record found ";
		}
		
		 return json_encode($json_arr); 	
	}
	





 	public function getMyList(){
		$db1 = \Config\Database::connect('writeDB');
		$db2 = \Config\Database::connect('readDB');
		// check required post data
		$json_arr=array();
		$user_token = $this->request->getVar('user_token');
		$search = $this->request->getVar('search');
		if($search==null){ $search='';	}
		$id = $this->request->getVar('id');
		if($id==null){ $id='';	}
		$limit = $this->request->getVar('limit');
		$maxRowsPerPage = $this->request->getVar('maxRowsPerPage');
		if($limit==null){ $limit=0;	}
		if($maxRowsPerPage==null){ $maxRowsPerPage=30;	}
		
		$validateToken=$this->validateToken();
		if($validateToken != "success"){ return $validateToken;  }
		$authAccess=$this->authAccess('myResult');
		if($authAccess != "success"){ return $authAccess; } 
		
		$query = $db2->query("select id, group_ids from sq_user where  user_token='$user_token' ");
		$user=$query->getRowArray();			
		$uid=$user['id'];
		
		if($search==''){
		$where="";
			if($id != ''){
					$where=" and sq_result.id='".$id."' ";
			}

		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open' and sq_result.uid='$uid'  $where order by sq_result.id desc limit $limit, $maxRowsPerPage");
		}else{
		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open' and sq_result.uid='$uid'  and (sq_quiz.quiz_name like '%$search%' or sq_result.id like '%$search%'  or sq_user.username like '%$search%'   or sq_user.email like '%$search%'     or sq_user.full_name like '%$search%'  ) order by sq_result.id desc limit $limit, $maxRowsPerPage");
			
		}
		
		$result=$query->getResultArray();
		if(count($query->getResultArray()) >= 1){
			$json_arr['status']="success"; 	$json_arr['message']="";
			$json_arr['data']=$result;
		}else{
			$json_arr['status']="failed"; 	$json_arr['message']="No record found ";
		}
		
		 return json_encode($json_arr); 	
	}
	
	public function view(){
		$db1 = \Config\Database::connect('writeDB');
		$db2 = \Config\Database::connect('readDB');
		// check required post data
		$json_arr=array();
		$user_token = $this->request->getVar('user_token');
		$search = $this->request->getVar('search');
		if($search==null){ $search='';	}
		$id = $this->request->getVar('id');
		if($id==null){ $id='';	}
		$limit = $this->request->getVar('limit');
		$maxRowsPerPage = $this->request->getVar('maxRowsPerPage');
		if($limit==null){ $limit=0;	}
		if($maxRowsPerPage==null){ $maxRowsPerPage=30;	}
		
		$validateToken=$this->validateToken();
		if($validateToken != "success"){ return $validateToken;  }
		$authAccess=$this->authAccess('resultView');
		if($authAccess != "success"){ return $authAccess; } 
		if($search==''){
		$where="";
			if($id != ''){
					$where=" and sq_result.id='".$id."' ";
			}

		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_quiz.duration, sq_quiz.correct_score, sq_quiz.incorrect_score, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open'  $where order by sq_result.id desc limit $limit, $maxRowsPerPage");
		}else{
		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_quiz.duration, sq_quiz.correct_score, sq_quiz.incorrect_score, sq_user.username,   sq_user.email,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open'  and (sq_quiz.quiz_name like '%$search%' or sq_result.id like '%$search%'  or sq_user.username like '%$search%'   or sq_user.email like '%$search%'     or sq_user.full_name like '%$search%'  )order by sq_result.id desc limit $limit, $maxRowsPerPage");
			
		}
		
        $result=$query->getRowArray();
        if(count($query->getResultArray()) >= 1){
            // Recompute on demand if metrics missing or mismatched
            $assigned_qids_len = count(array_filter(array_map('trim', explode(',', (string)$result['assigned_qids'])), 'strlen'));
            $attempted_s = (string)$result['attempted_questions'];
            if($attempted_s==='' || count(explode(',', $attempted_s)) != $assigned_qids_len){
                $re = $this->recomputeMetrics($id);
                if($re != null){ $result = $re; }
            }
            $json_arr['status']="success"; 	$json_arr['message']="";
            $correct_score=explode(',',$result['correct_score']);
            $attempted_questions=explode(',', (string)$result['attempted_questions']);
            $ind_score=explode(',', (string)$result['ind_score']);
            // Normalize lengths to number of assigned qids
            $qCount = count(array_filter(array_map('trim', explode(',', (string)$result['assigned_qids'])), 'strlen'));
            if(count($attempted_questions) < $qCount){ $attempted_questions = array_merge($attempted_questions, array_fill(0, $qCount - count($attempted_questions), 0)); }
            if(count($ind_score) < $qCount){ $ind_score = array_merge($ind_score, array_fill(0, $qCount - count($ind_score), 0)); }
			$nc=array();
			if(count($correct_score) <= 1){
				foreach(explode(',',$result['assigned_qids']) as $k => $v){
					$nc[]=$correct_score[0];
				}
			}
			$no_corrected=0;
			$no_incorrected=0;
            foreach($ind_score as $k => $val){
                if($val > 0){
                    $no_corrected +=1;
                }else{
                    if(isset($attempted_questions[$k]) && $attempted_questions[$k] == 1){
                        $no_incorrected +=1; 
                    }
                }
            }
			$max_score=array_sum($nc);
			$result['max_score']=$max_score;
			$result['attempted_no_questions']=array_sum($attempted_questions);
			$result['time_spent_in_min']=gmdate("H:i:s", $result['time_spent']);
			$result['no_corrected']=$no_corrected;
			$result['no_incorrected']=$no_incorrected;
			
			$json_arr['data']=$result;
		}else{
			$json_arr['status']="failed"; 	$json_arr['message']="No record found ";
		}
		
		 return json_encode($json_arr); 	
	}
	
	
	
	public function getQuestions(){
		$db1 = \Config\Database::connect('writeDB');
		$db2 = \Config\Database::connect('readDB');
		// check required post data
		$json_arr=array();
		$user_token = $this->request->getVar('user_token');
        $response_time = $this->request->getVar('response_time');
        $ind_score = explode(',', (string)$this->request->getVar('ind_score'));
        $ind_time = explode(',', (string)$this->request->getVar('ind_time'));
        $attempted_questions = explode(',', (string)$this->request->getVar('attempted_questions'));
        $assigned_qids = $this->request->getVar('assigned_qids');
		$rid = $this->request->getVar('rid');
		$validateToken=$this->validateToken();
		if($validateToken != "success"){ return $validateToken;  }
        // Include historical (soft-deleted) questions/options in reports so past attempts render fully
        $query = $db2->query("select sq_question.id, sq_question.question_type, sq_question.question, sq_question.description, sq_question.category_ids, sq_category.category_name from sq_question join sq_category on sq_category.id=sq_question.category_ids where sq_question.id in ($assigned_qids) ORDER BY FIELD(sq_question.id, $assigned_qids) ");
        $result=$query->getResultArray(); 
        $questions=$result;
        // For finalized results, show recorded answers regardless of response_time (save batches)
        $sql3="select id, question_id, user_response from sq_answer where  rid='$rid' ORDER BY FIELD(question_id, $assigned_qids)  ";
        $query3 = $db2->query($sql3);
        $answers=$query3->getResultArray(); 
		$user_response=array();
		foreach($answers as $ak => $answer){
			$user_response[$answer['question_id']][]=$answer['user_response'];
		}

        $sqlOption="select id, question_id, question_option, score from sq_option where question_id in ($assigned_qids) ORDER BY FIELD(question_id, $assigned_qids) ";
		  
		$query = $db2->query($sqlOption);
        $result=$query->getResultArray(); 
        $options=$result;
        // Build option id -> base64(text) map for quick lookup of user's selected text
        $optionTextById = array();
        foreach($options as $op){
            $optionTextById[(string)$op['id']] = base64_encode($op['question_option']);
        }
        // Normalize metric arrays to question count to prevent PHP notices and broken JSON
        $qidsList = array_filter(array_map('trim', explode(',', (string)$assigned_qids)), 'strlen');
        $qCount = count($qidsList);
        $norm = function($arr, $n, $cast='float'){
            $out = [];
            foreach($arr as $v){ $out[] = ($cast==='int'? (int)$v : (float)$v); }
            if(count($out) < $n){ $out = array_merge($out, array_fill(0, $n - count($out), 0)); }
            return $out;
        };
        $ind_score = $norm($ind_score, $qCount, 'float');
        $ind_time = $norm($ind_time, $qCount, 'int');
        // attempted_questions should be 0/1 integers
        $aq = [];
        foreach($attempted_questions as $v){ $aq[] = (int)$v; }
        if(count($aq) < $qCount){ $aq = array_merge($aq, array_fill(0, $qCount - count($aq), 0)); }
        $attempted_questions = $aq;
		$category_labels=array(); 
		$ques_arr=array();
        foreach($questions as $k => $val){
            // Normalize encoding and repair CSV-split question text if needed
            $qtxt = $this->toUtf8($val['question']);
            $dtxt = $this->toUtf8($val['description']);
            // If description is non-empty and question does not end with punctuation,
            // merge description into question for display (handles early CSV imports like
            // "En télétravail, quelle combinaison est la plus sécurisée ?")
            if(trim($dtxt) !== '' && !preg_match('/[\?\.!:]\s*$/u', trim($qtxt))){
                $q = rtrim($qtxt);
                $q = rtrim($q, ',');
                $qtxt = trim($q);
                if($qtxt !== ''){ $qtxt .= ', '; }
                $qtxt .= $dtxt;
                $dtxt = '';
            }
            $val['question']=base64_encode($qtxt);
            $val['description']=base64_encode($dtxt);
            $ques_arr[$k]['question']=$val;
			if(!isset($category_labels[$val['category_ids']])){
				$category_labels[$val['category_ids']]['category_name']=$val['category_name'];
				$category_labels[$val['category_ids']]['category_id']=$val['category_ids'];
				$category_labels[$val['category_ids']]['attempted_question']=0;
				$category_labels[$val['category_ids']]['correct']=0;
				$category_labels[$val['category_ids']]['incorrect']=0;
				$category_labels[$val['category_ids']]['score']=0;
				$category_labels[$val['category_ids']]['total_questions']=1;
				$category_labels[$val['category_ids']]['time']=$ind_time[$k];
			}else{
				$category_labels[$val['category_ids']]['total_questions'] +=1;
				$category_labels[$val['category_ids']]['time'] +=$ind_time[$k];
			}
            if(isset($attempted_questions[$k]) && $attempted_questions[$k] == 0){
                $category_labels[$val['category_ids']]['attempted_question'] +=0;
                $category_labels[$val['category_ids']]['score'] +=0;
                $category_labels[$val['category_ids']]['correct'] +=0;
                $category_labels[$val['category_ids']]['incorrect'] +=0;
            }else{
                if(isset($ind_score[$k]) && $ind_score[$k] > 0){
                    $category_labels[$val['category_ids']]['attempted_question'] +=1;
                    $category_labels[$val['category_ids']]['score'] +=$ind_score[$k];
                    $category_labels[$val['category_ids']]['correct'] +=1;
                    $category_labels[$val['category_ids']]['incorrect'] +=0;                     
                }else{
                    $category_labels[$val['category_ids']]['attempted_question'] +=1;
                    $category_labels[$val['category_ids']]['score'] += isset($ind_score[$k]) ? $ind_score[$k] : 0;
                    $category_labels[$val['category_ids']]['correct'] +=0;
                    $category_labels[$val['category_ids']]['incorrect'] +=1;                     
                }
            }
            if(isset($user_response[$val['id']])){
                // De-duplicate selected option ids while preserving first-seen order
                $seen = array();
                $selIds = array();
                foreach($user_response[$val['id']] as $sel){
                    $sid = (string)$sel;
                    if(!isset($seen[$sid])){ $seen[$sid]=true; $selIds[]=$sid; }
                }
                $ques_arr[$k]['user_response']=$selIds;                
                // Also provide user_response_text (base64-encoded, comma-separated) distinct
                $selTxt=array();
                foreach($selIds as $sid){
                    if(isset($optionTextById[$sid])){ $selTxt[] = $optionTextById[$sid]; }
                }
                if(count($selTxt)>0){ $ques_arr[$k]['user_response_text']=implode(',', $selTxt); }
            }else{
                $ques_arr[$k]['user_response']="";
                $ques_arr[$k]['user_response_text']='';
            }
            if($val['question_type']=="Multiple Choice Single Answer" || $val['question_type']=="Multiple Choice Multiple Answers" || $val['question_type']=="Short Answer"){
                $correctTxt = array();
                foreach($options as $ok => $oval){
                    if($oval['question_id']==$val['id']){
                        if($val['question_type']=="Short Answer"){
                            $oval['question_option']=str_replace(","," or ",$oval['question_option']);
                            $oval['question_option']=base64_encode($oval['question_option']);
                            $ques_arr[$k]['options'][]=$oval;
                        }else{
                            // MCQ: include option and collect correct text if score>0
                            if(isset($oval['score']) && (float)$oval['score'] > 0){
                                $correctTxt[] = base64_encode($oval['question_option']);
                            }
                            $oval['question_option']=base64_encode($oval['question_option']);
                            $ques_arr[$k]['options'][]=$oval; 
                        }
                    }
                }
                if(count($correctTxt)>0){ $ques_arr[$k]['correct_text'] = implode(',', $correctTxt); }
            }
		 }
		$category_labels_new=array();
		$i=0;
		foreach($category_labels as $catK => $ckval){
			$ckval['time']=gmdate("H:i:s", $ckval['time']);
			$category_labels_new[$i]=$ckval;
			$i +=1;
		}
		$json_arr['category_labels']=$category_labels_new;
		$json_arr['status']="success"; 	$json_arr['message']=""; $json_arr['data']=$ques_arr; return json_encode($json_arr); 


		
	}
	
	public function downloadReport(){
		helper("filesystem");
		$db1 = \Config\Database::connect('writeDB');
		$db2 = \Config\Database::connect('readDB');
		// check required post data
		$json_arr=array();
		$user_token = $this->request->getVar('user_token');
		$validateToken=$this->validateToken();
		if($validateToken != "success"){ return $validateToken;  }
		$authAccess=$this->authAccess('resultList');
		if($authAccess != "success"){ return $authAccess; } 
		$from=$this->request->getVar('fromDate');
		$to=$this->request->getVar('toDate');
		if($from==null){ $from=0;	}
		if($to==null){ $to=0;	}
		$fromDate=strtotime(str_replace("T"," ",$from));
		$toDate=strtotime(str_replace("T"," ",$to));
		$group_id=str_replace("T"," ",$this->request->getVar('group_id'));
		$where="";
		if($group_id != "0"){
			$where=" and  sq_user.group_ids='".$group_id."' ";
		}
		if($from != 0){
			$where=" and  sq_result.attempted_datetime >='".$fromDate."' ";
		}
		if($to != 0){
			$where=" and  sq_result.attempted_datetime <='".$toDate."' ";
		}
		$query = $db2->query("select sq_result.*, sq_quiz.quiz_name, sq_user.username,   sq_user.email, sq_user.group_ids,   sq_user.full_name from sq_result join  sq_quiz on sq_quiz.id=sq_result.quid join sq_user on sq_user.id=sq_result.uid where  sq_result.trash_status='0' and sq_result.result_status !='Open'  $where order by sq_result.id desc ");
		$result=$query->getResultArray();
		$csvData="Result ID,Username,Email,Full Name, Quiz Name, Obtained Marks, Obtained Percentage, Result, Attempted Time, Total Time Spent, Total Questions, Attempted Questions, Correct Answers, Incorrect Answers";
		foreach($result as $k => $row){
			$csvData=$csvData." ".PHP_EOL;
			$at=explode(',',$row['attempted_questions']);
			$attempted_questions=array_sum(explode(',',$row['attempted_questions']));
			$correct=0;
			$incorrect=0;
			$ind_score=explode(',',$row['ind_score']);
			foreach($ind_score as $k => $v){
				if($v <= 0){
					if($at[$k] == 1){
						$incorrect +=1;
					}
				}else{
					$correct +=1;
				}
			}
		$csvData=$csvData."".$row['id'].",".$row['username'].",".$row['email'].",".$row['full_name'].",".$row['quiz_name'].",".$row['obtained_score'].",".$row['obtained_percentage'].",".$row['result_status'].",".date('Y-m-d H:i:s',$row['attempted_datetime']).",".gmdate("H:i:s", $row['time_spent']).",".count(explode(',',($row['assigned_qids']))).",".$attempted_questions.",".$correct.",".$incorrect;
		}
		// Type#3 - Write file inside /public folder and return value
		$fill=time();
		$filename=getenv('FILE_UPLOAD_ABSOLUTE_PATH') . $fill.".csv";
		if (!write_file($filename, $csvData)){
		$json_arr['status']="failed"; 	$json_arr['message']="Unable to write file on server";
		}else{
		$json_arr['status']="success"; 	$json_arr['path']=$fill;
		}				
		return json_encode($json_arr); 
		
	}
	
	public function downloadFile($csv){
			$fileDown = readfile(getenv('FILE_UPLOAD_ABSOLUTE_PATH') . $csv .".csv");

		print_r($fileDown); 
	}
	
	public function makeRequiredArrayFormat($arr){
		
		$narr=array();
		foreach($arr as $k => $val){
		
			if(isset($narr[$val->name])){
				if(is_array($narr[$val->name])){
				$narr[$val->name][]=$val->value;
				}else{
				$narr[$val->name]=array($narr[$val->name],$val->value);
				}
			}else{
			$narr[$val->name]=$val->value;
			}
			
		}
		return $narr;
	}
	
	
	
	 
 	public function validateToken(){
		$db = \Config\Database::connect('readDB');
		$user_token = $this->request->getVar('user_token');
		$query=$db->query(" select id from sq_user where user_token='$user_token' ");
		if(count($query->getResultArray()) == 0){
			 $json_arr['status']="failed"; 	$json_arr['message']="Invalid token, Re-login"; return json_encode($json_arr);
		}
		
		return "success";	
		
	}
	
	public function authAccess($dataRequested=''){
		$db = \Config\Database::connect('readDB');
		$user_token = $this->request->getVar('user_token');
		$query=$db->query(" select id,account_type_id from sq_user where user_token='$user_token' ");
		$row=$query->getRowArray();
		$account_type_id=$row['account_type_id'];
		$sql=" select * from sq_account_type where id='$account_type_id' and (FIND_IN_SET('$dataRequested',access_permissions) || FIND_IN_SET('all',access_permissions))  ";
		$query=$db->query($sql);
		if(count($query->getResultArray()) == 0){
			 $json_arr['status']="failed"; 	$json_arr['message']="Permission denied to access requested data with given user's token"; return json_encode($json_arr);
		}
		return "success";
		
	}
	
	
}
