<?php

class AccsPage extends AdminPage
{
    function index()
    {
        $db = $this->services->getDB();
        return array(
			'accounts'=>$db->query("/*maxtime60*/".
			"SELECT account, apikey,accounts.email,
				(round(count(if(spamscore>0,1,NULL))/count(*)*100) || '%') as spams,
				count(*) as cnt,
				count(if(spamscore<0,1,NULL)) as ham,
				coalesce(created,from_unixtime(min(\"timestamp\"))) as date, coalesce(script||'','-') as \"JS\",
				(SELECT count(*) FROM account_messages WHERE account = accounts.id AND \"read\" = 'N') as unread,
				(SELECT host FROM posts_data JOIN posts_meta using(id) WHERE account = accounts.id LIMIT 1) as hosts
			FROM posts_meta
			LEFT JOIN accounts ON accounts.id=account
			GROUP BY account,apikey,accounts.email,accounts.created,accounts.script,accounts.id
			ORDER BY date DESC,cnt")->fetchAll(PDO::FETCH_ASSOC),
			'brief'=>true,
			'title'=>'Accounts (brief)',
		);
    }

	function detailed()
	{
		return array(
			'accounts'=>$this->services->getDB()->query("/*maxtime360*/".
			"SELECT account,apikey,accounts.email,
				(round(count(if(spamscore>0,1,NULL))/count(*)*100) || '%') as spams,
				count(*) as cnt,
				count(if(spamscore<0,1,NULL)) as ham,
				(SELECT count(*) FROM account_messages WHERE account = accounts.id AND \"read\" = 'N') as unread,
				substring(group_concat(distinct host),1,100) as hosts,
			    coalesce(created,from_unixtime(min(\"timestamp\"))) as date, coalesce(script||'','-') as \"JS\"
			FROM posts_meta JOIN posts_data on posts_meta.id = posts_data.id
			LEFT JOIN accounts on accounts.id=account
			GROUP BY if(account>0,account||'',host),account,apikey,accounts.email,accounts.id,accounts.created,accounts.script
			ORDER BY date DESC,cnt")->fetchAll(PDO::FETCH_ASSOC),

			'title'=>'Accounts (detailed)',
		);
	}

	function msg($acc, $msgid = NULL)
	{
	    $acc = intval($acc);
	    if (!$acc) throw new Exception("Invalid account #");

	    $account = $this->services->getDB()->query("SELECT * FROM accounts WHERE id = '$acc'")->fetchAll(PDO::FETCH_ASSOC);
	    $account = $account[0];
	    if ($account['id'] != $acc) throw new Exception("msg to wrong account WTF?");

	    $textarea = '';
	    if ($msgid = intval($msgid))
	    {
	        $textarea = $this->services->getDB()->query("SELECT * FROM account_messages WHERE account = '$acc' AND id = '$msgid'")->fetchAll(PDO::FETCH_ASSOC);
	        $textarea = $textarea[0]['message_html'];
	        $textarea = preg_replace('/(\r?\n)<br \/>/','\1',$textarea);
        }

	    return array(
	        'textarea' => $textarea,
	        'msgid' => $msgid,
	        'account' => $account,
	        'inbox' => $this->services->getDB()->query("SELECT * FROM account_messages WHERE account = '$acc' ORDER BY \"read\",sent,\"type\"")->fetchAll(PDO::FETCH_ASSOC),
	        'page_template'=>'accmsg',
	    );
    }

    function post_msg($acc)
    {
	    $acc = intval($acc);
	    if (!$acc) throw new Exception("Invalid account #");

        if (isset($_POST['read']))
        {
           $this->services->getDB()->exec("UPDATE account_messages SET \"read\" = IF(\"read\" = 'Y','N','Y') WHERE id = ".intval($_POST['read']));
        }
        else if (isset($_POST['delete']))
        {
           $this->services->getDB()->exec("DELETE FROM account_messages WHERE id = ".intval($_POST['delete']));
        }
	    else if (!empty($_POST['msg']))
	    {
	        $_POST['msg'] = preg_replace('/([^> \r]\r?\n)/','\1<br />',trim($_POST['msg']));

	        if (empty($_POST['type'])) $_POST['type'] = NULL;

	        if (!empty($_POST['msgid']))
	        {
	            $stat = $this->services->getDB()->prepareExecute("UPDATE account_messages SET message_html = ? WHERE id = ? AND account = ?",array($_POST['msg'],$_POST['msgid'],$acc));
            }
            else // add
            {
                $stat = $this->services->getDB()->prepareExecute("REPLACE INTO account_messages(account,type,message_html) VALUES(?,?,?)",array($acc,$_POST['type'],$_POST['msg']));
            }
        }
        return array('redirect'=>"accs/msg/$acc");
    }

	function post_index()
	{
		$this->services->getDB()->exec("/*maxtime60*/UPDATE accounts SET script='Y' WHERE (script != 'Y' or script is null) and exists(select true from posts_meta join posts_data using(id) where account=accounts.id and spamreason like '%JS challenge%')");

		return $this->index();
	}
}
