<?php
require_once 'headerDB.inc.php';
ignore_user_abort(true);

$updateGlobals=array();
$html=false; // indicates if we are outputting html

$updateGlobals['captureOutput']=(isset($_REQUEST['output']) && $_REQUEST['output']==='xml');
if ($updateGlobals['captureOutput']) {
    @ob_start();
}

// sanitise text fields
foreach ( array( 'title'=>false, 'description'=>true, 'desiredOutcome'=>true) as $key=>$val )
	if ( !empty( $_REQUEST[$key] ) )
			$_REQUEST[$key] = trimTaggedString( $_REQUEST[$key], 0, $val, false );

// get core variables first
$values=array();  // ensures that this is a global variable
$values['itemId'] = isset($_REQUEST['itemId'])?(int) $_REQUEST['itemId']:null;
$values['type'] = (isset($_REQUEST['type']))?$_REQUEST['type']:null;

$action = $_REQUEST['action'];
$updateGlobals['referrer'] = (isset($_REQUEST['referrer'])) ?$_REQUEST['referrer']:null;

$updateGlobals['multi']    = (isset($_REQUEST['multi']) && $_REQUEST['multi']==='y');
$updateGlobals['parents'] = (isset($_REQUEST['parentId']))?$_REQUEST['parentId']:array();
if (!is_array($updateGlobals['parents'])) $updateGlobals['parents']=array($updateGlobals['parents']);

if (isset($_REQUEST['wasNAonEntry'])) {  // toggling next action status on several items
    $updateGlobals['wasNAonEntry'] = explode(' ',$_REQUEST['wasNAonEntry']);
    $updateGlobals['isNA']=array();
    if (isset($_REQUEST['isNAs'])) $updateGlobals['isNA']=$_REQUEST['isNAs'];
}

if (isset($_REQUEST['isMarked'])) { // doing a specific action on several items (currently, the only option is to complete them)
    $updateGlobals['isMarked']=array();
    $updateGlobals['isMarked']=array_unique($_REQUEST['isMarked']); // remove duplicates
}

// some debugging - if debug is set to halt, dump all the variables we've got

if ($_SESSION['debug']['debug']) {
    echo "<html><head><title>Process Item</title></head><body>\n";
    $html=true;
    // debugging text - simply dump the variables, and quit, without processing anything
    log_array('$_REQUEST','$_SESSION','$action','$values','$updateGlobals');
    if (isset($updateGlobals['isNA'])) {
        log_value('array_diff(wasNAonEntry,isNA)',array_diff($updateGlobals['wasNAonEntry'],$updateGlobals['isNA']));
        log_value('array_diff(isNA,wasNAonEntry)',array_diff($updateGlobals['isNA'],$updateGlobals['wasNAonEntry']));
    }
} // END OF debugging text

$title='';

if ($updateGlobals['multi']) {
    // recursively do actions, looping over items
    if (isset($updateGlobals['wasNAonEntry']) && isset($updateGlobals['isNA'])) {  // toggling next action status on several items
        foreach (array_diff($updateGlobals['wasNAonEntry'],$updateGlobals['isNA']) as $values['itemId']) if ($values['itemId']) doAction('removeNA');
        foreach (array_diff($updateGlobals['isNA'],$updateGlobals['wasNAonEntry']) as $values['itemId']) if ($values['itemId']) doAction('makeNA');
    }
    if (isset($updateGlobals['isMarked'])) { // doing a specific action on several items
        foreach ($updateGlobals['isMarked'] as $nextItem) {
            $values=array('itemId'=>$nextItem); // reset the $values array each time, so that it only contains itemId
            doAction($action);
        }
    }
} else {
    if (isset($_REQUEST['doDelete']) && $_REQUEST['doDelete']==='y') $action='delete'; // override item-update if we are simply deleting
    doAction($action);
}

nextPage();
if ($html)
    echo "</body></html>";
else
    echo '</head></html>';
return;

/*========================================================================================
  main program finished - utility functions from here, below
========================================================================================*/

function doAction($localAction) { // do the current action on the current item; returns TRUE if succeeded, else returns FALSE
    global $values,$updateGlobals,$title;
    if ($values['itemId']) {
        $result=query('getitembrief',$values); // TOFIX - should really only do this query at the end, after processing, if necessary then
        if ($result) {
            $briefitem=$result[0];
            $title=($result)?$briefitem['title']:'title unknown';
        } else $briefitem=null;
    } else
        $title=(empty($_REQUEST['title']))?'':$_REQUEST['title'];

    log_text("Action here is: $localAction item {$values['itemId']} - $title");
    if ($title=='') $title='item '.$values['itemId'];
    if ($_SESSION['debug']['freeze']) return TRUE;

    switch ($localAction) {
        //-----------------------------------------------------------------------------------
        case 'category':
            $values['categoryId']=$_REQUEST['categoryId'];
            query('updateitemcategory',$values);
            query("touchitem",$values);
            $msg="Set category for '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'changeType':
            $values['oldtype']=$_REQUEST['oldtype'];
            changeType();
            $newtype=getTypes($values['type']);
            $msg="$newtype is now the type for item: '$title'";
            $updateGlobals['referrer']="item.php?itemId={$values['itemId']}&amp;referrer="
                .((empty($updateGlobals['referrer']))
                    ? "listItems.php?type={$values['oldtype']}"
                    : $updateGlobals['referrer']);
            break;
        //-----------------------------------------------------------------------------------
        case 'checkcomplete':
            $msg=doChecklist();
            break;
        //-----------------------------------------------------------------------------------
        case 'clearCheckmark':
            $values['dateCompleted']='NULL';
            query("completeitem",$values);
            $msg="Checkmark cleared from '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'complete':
            completeItem();
            $msg="Completed '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'context':
        case 'space':
            $values['contextId']=$_REQUEST['contextId'];
            query('updateitemcontext',$values);
            query("touchitem",$values);
            $msg="Set space context for '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'createbasic': // deliberately flows through to case create
        case 'create':
            retrieveFormVars();
            createItem();
            $msg="Created item: '$title'";
            if (isset($_REQUEST['addAsParentTo'])) {
                addAsParent();
                $msg.=" and added it as a parent";
            }
            break;
        //-----------------------------------------------------------------------------------
        case 'delete':
            deleteItem();
            $msg="Deleted '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'fullUpdate':
            retrieveFormVars();
            updateItem();
            $msg="Updated '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'makeNA':
            makeNextAction();
            $msg="'$title' is now a next action";
            break;
        //-----------------------------------------------------------------------------------
        case 'removeNA':
            removeNextAction();
            $msg="'$title' is no longer a next action";
            break;
        //-----------------------------------------------------------------------------------
        case 'tag':
            $values['tagname']=$_REQUEST['tag'];
            query('newtagmap',$values);
            query("touchitem",$values);
            $msg="Tagged '$title' with '{$values['tagname']}'";
            break;
        //-----------------------------------------------------------------------------------
        case 'time': // deliberately flows through to synonym timecontext
        case 'timecontext':
            $values['timeframeId']=$_REQUEST['timeframeId'];
            query('updateitemtimecontext',$values);
            query("touchitem",$values);
            $msg="Set time context for '$title'";
            break;
        //-----------------------------------------------------------------------------------
        case 'updateText':
            // overlay any values from $_REQUEST, defaulting to current values
            foreach (array('title','description','desiredOutcome') as $field)
                $values[$field] = (isset($_REQUEST[$field]))
                    ? iconv('UTF-8',$_SESSION['config']['charset'].'//IGNORE',$_REQUEST[$field])
                    : $briefitem[$field];
            $result=query('updateitemtext',$values);
            query("touchitem",$values);
            $msg="Updated '$title'";
            break;
        //-----------------------------------------------------------------------------------
        default: // failed to identify which action we should be taking, so quit
            return FALSE;
    }
    $_SESSION['message'][] = $msg;
    return TRUE; // we have successfully carried out some action
}

/* ===========================================================================================
    primary action functions
   ================================= */
function doChecklist() {
    global $values,$updateGlobals,$title;
    if (empty($_REQUEST['clearchecklist']))
        $markeditems=$updateGlobals['isMarked'];
    else
        $markeditems=array();
    $values['parentId']=$updateGlobals['parents'][0];
    if (!isset($values['dateCompleted']))
        $values['dateCompleted']="'".date('Y-m-d')."'";
    $sep='';
    $ids='';
    foreach ($markeditems as $id) {
        $ids.=$sep.(int) $id;
        $sep="','";
    }
    $values['itemfilterquery']="$ids";
    query("updatechecklist",$values);
    $msg  = ($cnt=count($markeditems))." checklist item"
            .( ($cnt===1) ? '' : 's' )
            .' marked complete';
    return $msg;
}
//===========================================================================
function deleteItem() { // delete all references to a specific item
    global $values;
    query("deleteitemstatus",$values);
    query("deleteitem",$values);
    query("deletelookup",$values);
    query("deletelookupparents",$values);
}
//===========================================================================
function createItem() { // create an item and its parent-child relationships
    global $values,$updateGlobals,$title;
    //Insert new records
    $result = query("newitem",$values);
    $values['newitemId'] = $GLOBALS['lastinsertid'];
    $result = query("newitemstatus",$values);
    setParents('new');
    $title=$values['title'];
    $values['itemId']=$values['newitemId'];
    updateTags();
		if ($values['dateCompleted']!=='NULL')
        completeItem();
}
//===========================================================================
function updateItem() { // update all the values for the current item
    global $values,$updateGlobals,$title;
    query("deletelookup",$values);
    query("updateitemattributes",$values);
    query("updateitem",$values);
    updateTags();
    if ($values['type'] === $values['oldtype']) {
        setParents('update');
    } else {
        // changing item type - sever child links
        query("deletelookupparents",$values);
    }
    if ($values['dateCompleted']==='NULL')
        query('completeitem',$values);
    else
        completeItem();
    $title=$values['title'];
}
//===========================================================================
function completeItem() { // mark an item as completed, and recur if required
    global $values;
		
    if (!isset($values['dateCompleted'])) $values['dateCompleted']="'".date('Y-m-d')."'";
    if (!isset($values['recur'])) {
        $testrow = query("testitemrepeat",$values);
        if ($testrow) {
            $values['deadline']  =$testrow[0]['deadline'];
            $values['recur']     =$testrow[0]['recur'];
            $values['tickledate']=$testrow[0]['tickledate'];
        }
    }
    if ( !empty($values['recur']) ) {
			$testcompletion = query( 'getdatecompleted', $values );
			if ( empty( $testcompletion[0]['dateCompleted'] ) ) {
				recurItem();
				return;
			}
		}
		// if we got here, then either there's no recurrence, or we already completed and 
		// recurred this item; now we're just changing the completion date
		makeComplete();
}
//===========================================================================
function makeNextAction() { // mark the current item as a next action
    global $values;
    $values['nextaction']='y';
    query('updatenextaction',$values);
}
//===========================================================================
function removeNextAction() { // remove the next action reference for the current item
    global $values;
    $values['nextaction']='n';
    query('updatenextaction',$values);
}
//===========================================================================
function changeType() {
    global $values;
    $values['isSomeday']=isset($_REQUEST['isSomeday'])?$_REQUEST['isSomeday']:'n';
    query("updateitemtype",$values);
    if (empty($_REQUEST['safe'])) {
        query("deletelookup",$values);
        query("deletelookupparents",$values);
        removeNextAction();
    }
}
/* ===========================================================================================
    utility functions for the primary actions
   =========================================== */

function updateTags() {
    global $values;
    query('removeitemtags',$values);
    if (!empty($values['alltags']))
        foreach ($values['alltags'] as $tag)
            if (!empty($tag)) {
                $values['tagname']=trim($tag);
                query('newtagmap',$values);
            }
}
//===========================================================================
function addAsParent() {
    global $values;
    // we need to make the item we've just created, a parent of the item with id addAsParentTo
    $tempvalues=array('parentId'=>$values['newitemId'],'newitemId'=>$_REQUEST['addAsParentTo']);
    $result = query("newparent",$tempvalues);
}
//===========================================================================
function retrieveFormVars() {
    global $updateGlobals,$values;

    // key variables
    $values['oldtype'] = (empty($_REQUEST['oldtype'])) ? $values['type'] : $_REQUEST['oldtype'];

    foreach ( array('type'=>'i','title'=>'untitled','description'=>''
            ,'desiredOutcome'=>'','categoryId'=>0,'contextId'=>0
            ,'timeframeId'=>0) as $field=>$default) {
        if (empty($_REQUEST[$field]))
            $values[$field] = $default;
        elseif (empty($_REQUEST['fromjavascript']))
            $values[$field] = $_REQUEST[$field];
        else {
            $values[$field] = iconv('UTF-8',$_SESSION['config']['charset'].'//IGNORE',$_REQUEST[$field]);
        }
    }
    $tags=(isset($_REQUEST['tags']))?strtolower($_REQUEST['tags']):'';
    $values['alltags']=array_unique(explode(',',$tags));
    
    // binary yes/no
    foreach (array('nextaction','isSomeday') as $field)
        $values[$field] = (isset($_REQUEST[$field]) && $_REQUEST[$field]==="y")?'y':'n';

    // dates
    foreach ( array('tickledate','dateCompleted','deadline') as $field)
       $values[$field]  = (empty($_REQUEST[$field])) ? "NULL" : ("'".date('Y-m-d',strtotime($_REQUEST[$field]))."'");

    if (    empty($_REQUEST['FREQtype'])
        || $_REQUEST['FREQtype']==='NORECUR' 
        || ($_REQUEST['FREQtype']==='TEXT' && empty($_REQUEST['icstext']))) {
        $values['recur']=null;
        $values['recurdesc']=null;
    } else {
    
        list($values['recur'],$values['recurdesc'],$vevent) = processRecurrence($values);
        
        if (    !empty($values['recur'])
            && ( empty($values['deadline'])   || $values['deadline']==='NULL'   )
            && ( empty($values['tickledate']) || $values['tickledate']==='NULL' ) ) {
            
            // haven't got a startdate, so use what the next recurrence date would be
            $nextdue=getNextRecurrence($values,$vevent);
            if ($nextdue) $values['deadline']="'$nextdue'";
            log_text("Forcing deadline where none given - $nextdue");

        }

    }

    log_value('retrieved form vars',$values);
}
//===========================================================================
function recurItem() {
    global $values,$updateGlobals;
    require_once 'iCalcreator.class.inc.php';

  $nextdue=getNextRecurrence($values);

    // before processing the next due date, do some house-cleaning and preparation
    $values['oldDateCompleted']=$values['dateCompleted'];
    if ($_SESSION['config']['storeRecurrences']) {
        $values['oldid']=$values['itemId'];
        $copy=getItemCopy();
        makeComplete();
        $values=array_merge($values,$copy);
        $updateGlobals['parents']=$copy['parents'];
        if (isset($updateGlobals['isNA']) && in_array($values['itemId'],$updateGlobals['isNA']))
            $values['nextaction']='y';
    }
    $values['dateCompleted']="NULL";
    if (empty($values['tickledate'])) $values['tickledate']='NULL';

    // now process the next due date
    if (empty($nextdue)) {
        $msg="There are no further occurrences of item {$values['itemId']} - {$values['title']}";
        log_text($msg);
        $_SESSION['message'][] = $msg;
    } else {
        $values['dateCreated']=date('Y-m-d');
        // now need to set tickle date (either to NULL, or to date in quotes)
        if (empty($values['deadline']) || $values['deadline']==='NULL') {
            $values['tickledate']="'$nextdue'";
            $values['deadline']='NULL';
        } else {
            if ($values['tickledate']!=='NULL')
                $values['tickledate']= date( "'Y-m-d'" ,
                     strtotime(str_replace("'",'',$values['tickledate']))
                   + (   strtotime($nextdue)
                       - strtotime(str_replace("'",'',$values['deadline']))
                     )
                );
            $values['deadline']="'$nextdue'";
        }
        log_text("new deadline={$values['deadline']}, new tickler={$values['tickledate']}");
        if ($_SESSION['config']['storeRecurrences']) {
            $values['alltags']=explode(',',$values['tagname']);
            createItem();
        }
    } // end of processing next due date
    
    if (!$_SESSION['config']['storeRecurrences']) {
        query("updatedeadline",$values);
        query("completeitem",$values); // reset completed date to null, and touch the last modified date
    }
}
//===========================================================================
function getItemCopy() { // retrieve values for the current item, and store in the $values array
    global $values,$updateGlobals;
    $values['filterquery']=' WHERE '.sqlparts('singleitem',$values);
    $result = query("selectitem",$values,array());
    $copy=($result) ? $result[0] : array();
    // now get parents
    $result=query("selectparents",$values,array());
    $copy['parents']=array();
    if ($result)
        foreach ($result as $parent)
            $copy['parents'][]=$parent['parentId'];
    log_array(array('Retrieved record for copying:'=>$values,"Parents:"=>$copy['parents']));
    return $copy;
}
//===========================================================================
function setParents($new) {
    global $values,$updateGlobals;
    log_value('parents',$updateGlobals['parents']);
    foreach ($updateGlobals['parents'] as $values['parentId'])
        if ($values['parentId'])
           $result = query($new."parent",$values);
}
//===========================================================================
function makeComplete() { // mark an action as completed
    global $values;
    query("completeitem",$values);
}

/* ===========================================================================================
    general utility functions that don't modify the database
   ========================================================= */
function buildCreateChildURL($URL) {
    global $values;
    foreach ( array(
      'categoryId'=>'categoryId','contextId'=>'contextId',
      'timeframeId'=>'timeframeId',
      'suppress'=>'suppress','deadline'=>'deadline',
      'isSomeday'=>'isSomeday','tickledate'=>'tickledate'
      ) as $key=>$cat )
          if (!empty($values[$key]) && $values[$key]!='NULL')
            $URL.="&amp;$cat=".str_replace("'","",$values[$key]);

    return $URL;
}
// ===================================================================
function nextPage() { // set up the forwarding to the next page
    global $values,$updateGlobals,$action;
    $t = (isset($values['oldtype']))?$values['oldtype']:((isset($values['type']))?$values['type']:null);
    $key='afterCreate'.$t;
    $id=(empty($values['newitemId']))?$values['itemId']:$values['newitemId'];
    $nextURL='';
    $tst=false;
    if (empty($_REQUEST['afterCreate'])) {
        $submitbuttons=array('parent','item','list','another','child','referrer');
        foreach ($submitbuttons as $testbutton) if (isset($_REQUEST["{$testbutton}Next"])) {
            $_SESSION[$key]=$tst=$testbutton;
            break;
        }
    } else {
        $_SESSION[$key] = $tst = $_REQUEST['afterCreate'];
    }
    if (empty($tst)) {
      if (!empty($updateGlobals['referrer']))
        $tst = $updateGlobals['referrer'];
      elseif (!empty($_SESSION[$key]))
        $tst = $_SESSION[$key];
      elseif (!empty($_SESSION['config'][$key]))
        $tst = $_SESSION['config'][$key];
    }
    if ($action=='delete' && ($tst=='item' || !$tst))
        $tst='parent'; // can't return to viewing the item if we've just deleted it, so force view of parent
        
    if (!$tst) $tst='list'; // if everything else has failed, just view the list
    
    if ($tst==='referrer')
      $tst=(empty($updateGlobals['referrer']) )
                  ? (empty($_SESSION["lastfilter$t"])?'':$_SESSION["lastfilter$t"])
                  : $updateGlobals['referrer'];
    switch ($tst) {
    
        case "another" :
            $nextURL="item.php?type=$t";
            if (!empty($updateGlobals['parents'])) {
                $parentlist= (is_array($updateGlobals['parents']))
                            ?implode(',',$updateGlobals['parents'])
                            :$updateGlobals['parents'];
                if ($parentlist!='') $nextURL.="&amp;parentId=$parentlist";
            }
            $nextURL=buildCreateChildURL($nextURL);
            if (!empty($values['nextaction']) && $values['nextaction']==='y')
              $nextURL.="&amp;nextonly=true";
            break;
            
        case 'child'   :
            $child=getChildType($values['type']);
            $nextURL="item.php?parentId=$id&amp;type={$child[0]}";
            if ($child[0]==='a') $nextURL.='&amp;nextonly=true';
            $nextURL=buildCreateChildURL($nextURL);
            break;
            
        case "item"    :
            $nextURL="itemReport.php?itemId=$id";
            break;
            
        case "list"       :
            $nextURL="listItems.php?type=$t";
            if (!empty($values['isSomeday']) && $values['isSomeday']==='y') {
                $nextURL.='&someday=true';
            } elseif (!empty($values['tickledate']) && time() < strtotime($values['tickledate']) ) {
                $nextURL.='&tickler=true';
            }
            if (!empty($values['nextaction']) && $values['nextaction']==='y') {
                $nextURL.='&nextonly=true';
            }
            break;
            
        case "parent"  :
            $nextURL=(count($updateGlobals['parents']))
                        ?('itemReport.php?itemId='.$updateGlobals['parents'][0])
                        :'orphans.php';
            break;
            
        default        :
            $nextURL = (is_array($tst)) ? $tst[0] : $tst;
            break;
    }
    log_value('referrer',$updateGlobals['referrer']);
    if (strpos($nextURL,'nextId=0')!==false) {
        if (empty($_REQUEST['referrer']) || strpos($_REQUEST['referrer'],'nextId=0')) {
            $_SESSION[$key]=$tst;
            $nextURL='';
        } else {
            $nextURL=str_replace('nextId=0','nextId='.$values['newitemId'],$nextURL);
            $_SESSION[$key]=$tst;
            $_SESSION['message'][]='Creation of this '.getTypes($values['type']).' has been suspended while parent is created';
        }
    }
    if ($nextURL=='')
        $nextURL="listItems.php?type=$t";
    else 
        $nextURL=html_entity_decode($nextURL);
    
    if ($updateGlobals['captureOutput']) {
        if ($values['itemId']) {
            $result=query('selectlastmodified',$values);
            if ($result) $values['lastModified']=$result[0]['lastModified'];
        }
        $outtext=$_SESSION['message'];
        $_SESSION['message']=array();
        $logtext=ob_get_contents();
        ob_end_clean();
        if (!headers_sent()) {
            $header="Content-Type: text/xml; charset=".$_SESSION['config']['charset'];
            header($header);
        }
        // NB don't put line breaks (\n) into the XML - it breaks things!
        echo '<?xml version="1.0" ?',"><gtdphp>"; // encoding="{$_SESSION['config']['charset']}"
        echo "<values>",
             "<action>$action</action>";
        foreach ($values as $key=>$val) {
            switch ($key) {
                //------------------------------------------------
                case 'categoryId':       // deliberately flows through
                case 'contextId':        // deliberately flows through
                case 'isSomeday':        // deliberately flows through
                case 'itemId':           // deliberately flows through
                case 'newitemId':        // deliberately flows through
                case 'nextaction':       // deliberately flows through
                case 'oldid':            // deliberately flows through
                case 'oldtype':          // deliberately flows through
                case 'parentid':         // deliberately flows through
                case 'timeframeId':      // deliberately flows through
                case 'type':
                    echo "<$key>",makeclean($val),"</$key>";
                    break;
                //-------------------------------------------------------
                case 'childfilterquery': // deliberately flows through
                case 'filterquery':      // deliberately flows through
                case 'parentfilterquery':// deliberately flows through
                case 'parents':
                    // suppress reporting these values
                    break;
                //-------------------------------------------------------
                case 'tickledate':
                  if (!empty($val) && $val!=='NULL')
                    echo '<unixtickledate>'
                        ,strtotime(str_replace("'",'',$val))
                        ,'</unixtickledate>';
                     // deliberately flows through
                case 'dateCompleted':    // deliberately flows through
                case 'dateCreated':      // deliberately flows through 
                case 'deadline':         // deliberately flows through
                case 'oldDateCompleted': 
                    echo "<$key>"
                        ,($val==='NULL' || empty($val)) ? '' :
                            ('<![CDATA['
                            .date($_SESSION['config']['datemask'],
                                strtotime(str_replace("'",'',$val)))
                            .']]>')
                        ,"</$key>";
                    break;
                //-------------------------------------------------------
                case 'description':      // deliberately flows through
                case 'desiredOutcome':   
                    $val=nl2br($val);    // deliberately flows through
                default:                 // by default, we wrap things in CDATA for safety
                    echo "<$key>"
                        ,("$val"==="") ? '' : "<![CDATA[$val]]>"
                        ,"</$key>";
                    break;
                case 'lastModified':
                    if ($val) echo "<$key><![CDATA["
                            ,date($_SESSION['config']['datemask'].' H:i:s',$val)
                            ,"]]></$key>";
                    break;
                //-------------------------------------------------------
                case 'tagname':
                case 'alltags':
                    echo "<$key><![CDATA[";
                    if (array_key_exists('alltags',$values)) {
                        echo implode(',',$values['alltags']);
                    } else if (is_array($values['tagname']))
                        echo implode(',',$values['tagname']);
                    else echo $values['tagname'];
                    echo "]]></$key>";
                    break;
                //-------------------------------------------------------
            }
            echo "";
        }
        echo "</values><result>";
        if (!empty($outtext)) foreach ($outtext as $line) echo "<line><![CDATA[$line]]></line>";
        echo "</result>"
            ,"<log><![CDATA[$logtext]]></log>"
            ,"</gtdphp>";
        exit;
    } else nextScreen($nextURL);
}
//===========================================================================

// php closing tag has been omitted deliberately, to avoid unwanted blank lines being sent to the browser
