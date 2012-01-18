<?php
include_once 'headerDB.inc.php';

$values=array();
$values['itemId'] = (int) $_GET['itemId'];
$pageURL="{$pagename}.php?itemId={$values['itemId']}";
//Get item details
$values['childfilterquery']=' WHERE '.sqlparts('singleitem',$values);
$values['filterquery']='';
$values['extravarsfilterquery'] ='';
$result = query("getitemsandparent",$values);
if (!$result) {
    include_once 'header.inc.php';
    echo ("<p class='error'>Failed to find item {$values['itemId']}</p>");
    include_once 'footer.inc.php';
    die;
}

$item=$result[0]; // $item will store the values for the item being viewed

$values['isSomeday']=($item['isSomeday']=="y")?'y':'n';
$values['type']=$item['type'];

/* -------------------------------------------
    Find previous and next projects
*/
if (isset($_SESSION['idlist-'.$item['type']])) {
    $ndx=$_SESSION['idlist-'.$item['type']];
    unset($result);
} else {
    $values['filterquery']  = " WHERE ".sqlparts("typefilter",$values);
    $values['filterquery'] .= " AND ".sqlparts("activeitems",$values);
    $values['filterquery'] .= " AND ".sqlparts("pendingitems",$values);
    $values['filterquery'] .= " AND ".sqlparts("issomeday",$values);
    $result = query("getitems",$values);
    $c=0;
    $ndx=array();
    if ($result) {
        foreach ($result as $row) $ndx[]=$row['itemId'];
        $_SESSION['idlist-'.$item['type']]=$ndx;
    }
}

$cnt=count($ndx);
if($cnt>1) {
    $key=array_search($values['itemId'],$ndx);
    if ($key===false) {
        $next=0;
        $prev=$cnt-1;
    } else {
        if ($key==0)
            $prev=$cnt-1;
        else
            $prev=$key-1;
            
        if ($key==$cnt-1)
            $next=0;
        else
            $next=$key+1;
    }
    $previousId=$ndx[$prev];
    $nextId    =$ndx[$next];
    if (isset($result)) {
        $previoustitle=$result[$prev]['title'];
        $nexttitle    =$result[$next]['title'];
    } else {
        $previtem = query("selectitemtitle",array('itemId'=>$previousId));
        $previoustitle=$previtem[0]['title'];
        $nextitem = query("selectitemtitle",array('itemId'=>$nextId));
        $nexttitle    =$nextitem[0]['title'];
    }
}
/*
    Got previous and next projects
----------------------------------------------------------*/

$afterTypeChange=$pageURL;
if (empty($item['parentId'])) {
    $pids=$pnames=array();
} else {
    $pids=explode(',',$item['parentId']);
    $pnames=explode($_SESSION['config']['separator'],$item['ptitle']);
}
$values['parentId']=$values['itemId'];
/* -------------------------------------------------------------------
    get all children for this item, and accumulate them into arrays,
    grouped by completion status and child item type
*/

// initiate arrays for children tables
$maintableReferrer=$pageURL;

$AcreateItemId=$AnoEntries=$Athistableid=$AwasNAonEntry=$Afootertext=$AdispArray
    =$Amaintable=array();
$childtype=getChildType($item['type']); // array of item types that can be children of this item
if (false!==($ndx=array_search('p',$childtype,true)))
    array_splice($childtype,$ndx+1,0,'s');
// loop to build tables of children, by completion status and type
if (!empty($childtype)) foreach (array('n','y') as $comp) foreach ($childtype as $thistype) {
    // reset arrays for each table
    $wasNAonEntry=$footertext=$dispArray=$maintable=array();

    $thistableid="i$comp$thistype"; // set the unique id for each table
    $thischildtype=getTypes($thistype,$item['type']);
    $sectiontitle=(($comp==="y")?'Completed ':'').$thischildtype.'s';
    /* -------------------------------------
        Query: select children for this type
    */
    if ($thistype==='s') {
       $values['type']='p';
       $values['isSomeday']='y';
       $values['filterquery'] ='';
    } else {
        $values['isSomeday']='n';
        $values['type']=$thistype;
	    $values['filterquery'] = " AND ".sqlparts("typefilter",$values); // only filter on type if not a someday
    }
    $values['filterquery'] .= " AND ".sqlparts("issomeday",$values);

    $q=($comp==='y')?'completeditems':'pendingitems';  //suppressed items will be shown on report page
	$values['filterquery'] .= " AND ".sqlparts($q,$values);
    $maintable = query("getchildren",$values);
    if (!$maintable) $maintable=array();
    /*
        end of query
    ----------------------------------------*/
    if ($comp==='n') {
        // inherit some defaults from parent:
        $createItemId="0&amp;parentId={$values['itemId']}&amp;type=$thistype";
        foreach (array('categoryId','contextId') as $field)
            if ($item[$field]) $createItemId.="&amp;$field={$item[$field]}";
        if ($item['deadline']) $createItemId.="&amp;deadline=".date('Y-m-d',$item['deadline']);
    }
    $addnew="<a href='item.php?itemId$createItemId' class='creator'>Add new $thischildtype</a>";
    // prepare text to display for use if there are no children:
    $noEntries= "<h3>No $sectiontitle".(($comp==='n')?" - $addnew":'' )."</h3>";
    /* set limit on number of children to dispay, if we are processing completed items,
        and the number of returned items is greater than the user-configured display limit */
    if ($comp==='y' && $_SESSION['config']['ReportMaxCompleteChildren']
        && count($maintable) > $_SESSION['config']['ReportMaxCompleteChildren']
        && $item['type']!=='L' && $item['type']!=='C' ) {
        $limit=$_SESSION['config']['ReportMaxCompleteChildren'];
        $footertext[]="<a href='listItems.php?type=$thistype&amp;parentId={$values['parentId']}&amp;completed=true'".
            (($_SESSION['useLiveEnhancements'])?" onclick='return GTD.toggleHidden(\"$thistableid\",\"f$thistableid\",\"table-row\");'":'').
            ">".(count($maintable)-$limit)." more... (".count($maintable)." items in total)</a>";
    } else {
        $limit=count($maintable);
        if ($comp==='n')
            $footertext[]=$addnew;
    }
    /* ------------------------------------------------
        decide which fields to tabulate, based on child item type, and completion status
    */
	$shownext= ($comp==='n') && ($values['type']==='a' || $values['type']==='w');
	$suppressed=0;
    $trimlength=$_SESSION['config'][($comp==="n")?'trimLengthInReport':'trimLength'];
    if($trimlength) {
	    $descriptionField='shortdesc';
	    $outcomeField='shortoutcome';
    } else {
        $descriptionField='description';
        $outcomeField='desiredOutcome';
    }
    if ($shownext) $dispArray['NA']='NA';
    $dispArray['title']=$sectiontitle;
    $dispArray[$descriptionField]='Description';

    switch ($values['type']) {
        case 'T':
            // prevent display of category for (check)list items
            break;
        case 'a': // deliberately flows through to 'w'
        		if ($comp=="n") {
          			$dispArray['recurdesc']='Repeat';
            }
        case 'w': // deliberately flows through to 'r'
            if ($comp=="n") {
                $dispArray['tickledate']='Suppress until';
    			      $dispArray['deadline']='Deadline';
            }
        case 'r':
            $dispArray['context']='context';
            $dispArray['timeframe']='time';
            break;
        case 'm': // deliberately flows through to 'p;
        case 'v': // deliberately flows through to 'p;
        case 'o': // deliberately flows through to 'p;
        case 'g': // deliberately flows through to 'p;
        case 's': // deliberately flows through to 'p;
        case 'p': // deliberately flows through to default;
            $dispArray[$outcomeField]='Outcome';
        default:
            $dispArray['category']='category';
            break;
    }

    $dispArray['dateCreated']='Date Created';
	if ($comp=="n" || $item['type']==='C') {
		$dispArray['checkbox']='Complete';
	} else {
		$dispArray['dateCompleted']='Date Completed';
	}
    foreach ($dispArray as $key=>$val) $show[$key]=true;
    /*  finished choosing which fields to display
        ----------------------------------------------------------
        now process the query result, row by row, ready for tabulation
    */
	$i=0;

    while ($i < count($maintable)) {
        $row=&$maintable[$i];
        $cleantitle=makeclean($row['title']);

        $row['doreport']=!($row['type']=="a" || $row['type']==="r" || $row['type']==="w" || $row['type']==="i");


        if ($i >= $limit) {
            if ($_SESSION['useLiveEnhancements']) {
                $row['row.class']='togglehidden';
            } else {
                array_splice($maintable,$i);
                break;
            }
        }
        $row['type']=$childtype;
        $row[$descriptionField]=$row['description'];
        $row[$outcomeField]=$row['desiredOutcome'];
		$row['category']=makeclean($row['category']);

		$row['context']=makeclean($row['cname']);
		$row['context.title']='Go to '.$row['context'].' context report';

		$row['timeframe']=makeclean($row['timeframe']);
        $row['timeframe.title']='Go to '.$row['timeframe'].' time-context report';

		if ($comp==='n') {
			if ($row['tickledate']>time()) { // item is not yet tickled - count it, then skip displaying it
				$suppressed++;
				if ($_SESSION['useLiveEnhancements'])
                    $row['row.class']='togglehidden';
                else {
                    array_splice($maintable,$i,1);
                    continue;
                }
			}

            if (!empty($row['deadline'])) {
                $deadline=prettyDueDate($row['deadline'],$row['daysdue']);
                $row['deadline']      =$deadline['date'];
                $row['deadline.class']=$deadline['class'];
                $row['deadline.title']=$deadline['title'];
            }

			if ($shownext) {
                $row['NA']=$comp!=="y" && $row['nextaction']==='y';
                $row['NA.title']='Mark as a Next Action';
                if ($row['NA']) array_push($wasNAonEntry,$row['itemId']);
            }
		}

		$row['checkbox.title']="Mark $cleantitle ".
                ( ($comp==='y') ? 'in' : '' ).
                "complete";
		$row['checkboxname']='isMarked[]';
		$row['checkboxvalue']=$row['itemId'];
        $row['checkboxchecked']=($comp==='y');

		$i++;
    }
    unset($row);
    /*  finished row-by row processing
        ------------------------------------------------
        now set table footer
    */
	if ($suppressed) {
        $is=($suppressed===1)?'is':'are';
        $also=(count($maintable))?'also':'';
        $plural=($suppressed===1)?'':'s';
		array_unshift($footertext,
            "<a href='listItems.php?tickler=true&amp;type={$thistype}&amp;parentId={$values['parentId']}'".
            (($_SESSION['useLiveEnhancements'])?" onclick='return GTD.toggleHidden(\"$thistableid\",\"f$thistableid\",\"table-row\");'":'').
            ">There $is $also $suppressed tickler $thischildtype$plural not yet due for action</a>"
        );
	}
    /*  finished table footer
        ------------------------------------------------
        accumulate arrays for this child type and completion status,
        into the master arrays of all children
    */

    $AwasNAonEntry[$comp][$thistype]=$wasNAonEntry;
    $Athistableid[$comp][$thistype] =$thistableid;
    $AwasNAonEntry[$comp][$thistype]=$wasNAonEntry;
    $Afootertext[$comp][$thistype]  =$footertext;
    $AdispArray[$comp][$thistype]   =$dispArray;
    $Amaintable[$comp][$thistype]   =$maintable;
    $AnoEntries[$comp][$thistype]   =$noEntries;
    $AcreateItemId[$comp][$thistype]=$createItemId;
}
/*
    end of loop to build tables of children
    ============================================================================
    and end of data processing
    ============================================================================
    got all data - now display page
*/
$title="View ".makeclean($item['title']);

$titlefull="<span class='noprint hoverbox'>";
if(isset($previousId))
    $titlefull.= "<a href='itemReport.php?itemId=$previousId' title='Previous: "
        .makeclean($previoustitle)."'> &lt; </a>";

$titlefull.= " <a href='item.php?itemId={$values['itemId']}'>"
        ." <img src='themes/{$_SESSION['theme']}/edit.gif' alt='Edit ' title='Edit' /> "
        ."</a> ";

if(isset($nextId))
    $titlefull.= " <a href='itemReport.php?itemId=$nextId' title='Next: "
        .makeclean($nexttitle)."'> &gt; </a> \n";

$titlefull.= "</span>".getTypes($item['type'])." Report: "
    .makeclean($item['title']);

if ($item['isSomeday']==='y')
    $titlefull.= ' (Someday) ';

include_once 'headerHtml.inc.php';
gtd_handleEvent(_GTD_ON_DATA,$pagename);
include_once 'header.inc.php';

if ($item['type']==='i')
    echo "<div class='editbar'>"
        ,"[<a href='assignType.php?itemId={$values['itemId']}&amp;referrer=$afterTypeChange'>Set type</a>] \n"
        ,"</div>";
/* --------------------------------------------------
    display values for this item
*/
?>
<table class='mainReport' summary='item attributes'><tbody>
<?php
//Item details
if ($item['description']) 
    echo "<tr class='col-fulldes'><th>Description:</th><td>"
        ,nl2br(escapeChars($item['description'])),"</td></tr>\n";

if ($item['desiredOutcome']) 
    echo "<tr class='col-fulloutcome'><th>Desired Outcome:</th><td>"
        ,nl2br(escapeChars($item['desiredOutcome'])),"</td></tr>\n";

if (!empty($pids)) {
    echo "<tr class='col-parents'><th>Parents:&nbsp;</th><td>";
    $brk='';
    foreach ($pids as $pkey=>$pid) {
        $thisparent=makeclean($pnames[$pkey]);
        echo "$brk<a href='itemReport.php?itemId=$pid' title='Go to the $thisparent report'>$thisparent</a> ";
        $brk=', ';
    }
    echo "</td></tr>\n";
}

if ($item['categoryId']) 
    echo "<tr><th class='col-category'>Category:</th><td><a href='listItems.php?categoryId={$item['categoryId']}&amp;type={$item['type']}'>"
        ,makeclean($item['category']),"</a></td></tr>\n";

if ($item['contextId']) 
    echo "<tr class='col-context'><th>Space Context:</th><td><a href='listItems.php?contextId={$item['contextId']}&amp;type={$item['type']}'>"
        ,makeclean($item['cname']),"</a></td></tr>\n";

if ($item['timeframeId'])
    echo "<tr class='col-timeframe'><th>Time Context:</th><td><a href='listItems.php?timeframeId={$item['timeframeId']}&amp;type={$item['type']}'>"
        ,makeclean($item['timeframe']),"</a></td></tr>\n";

if ($item['deadline']) {
    $deadline=prettyDueDate($item['deadline'],$item['daysdue']);
    echo "<tr class='col-deadline'><th>Deadline:</th><td"
        ,(empty($item['dateCompleted']))
            ? " class='{$deadline['class']}' title='{$deadline['title']}'"
            : ''
        ,'>',$deadline['date'],"</td></tr>\n";
}

if ($item['type']==='a' || $item['type']==='w') 
    echo "<tr class='col-NA'><th>Next Action?</th><td>"
        ,($item['nextaction']==='y')?'Yes':'No',"</td></tr>\n";

if (!empty($item['recurdesc']) || !empty($item['recur']))
    echo "<tr class='col-recurdesc'><th>Repeat</th><td>{$item['recurdesc']} ({$item['recur']})</td></tr>\n";

if (!empty($item['tickledate']))
	echo "<tr class='col-tickle'><th>Suppressed Until:</th><td>"
        ,date($_SESSION['config']['datemask'],$item['tickledate'])
        ,"</td></tr>\n";

if (!empty($item['tags'])) {
	echo "<tr class='col-tags'><th>Tags:</th><td>";
    $taglist=explode(',',$item['tags']);
    $sep='';
    foreach ($taglist as $tag) {
      echo "$sep<a href='listItems.php?tags=$tag'>$tag</a>";
      $sep=', ';
    }
    echo "</td></tr>\n";
}

echo "<tr class='col-dateCreated'><th>Created:</th><td>"
    ,date($_SESSION['config']['datemask'],$item['dateCreated'])
    ,"</td></tr>\n";

if ($item['lastModified'])
    echo "<tr class='col-lastmodified'><th>Last modified:</th><td>"
        ,date($_SESSION['config']['datemask'].' H:i:s',$item['lastModified'])
        ,"</td></tr>\n";

echo "<tr class='col-completed'>\n";

if ($item['dateCompleted']) {
    echo "<th>Completed On:</th><td>"
        ,date($_SESSION['config']['datemask'],$item['dateCompleted'])
        ,"</td>\n";

    } else { ?>
    <th>Complete</th>
    <td>
        <form method='post' action='processItems.php'>
            <div>
                <input type='submit' name='complete' value='Today' id='completereport' />
                <input type='hidden' name='action' value='complete' />
                <input type='hidden' name='referrer' value='<?php echo $pageURL; ?>' />
                <input type='hidden' name='itemId' value='<?php echo $values['itemId']; ?>' />
            </div>
        </form>
    </td>
<?php }
echo "</tr>\n";
?>
</tbody></table>
<?php
/*
    finished displaying item values
 ============================================================================ */
if (empty($childtype)) {
    include_once 'footer.inc.php';
    exit;
}
/* ============================================================================
    now display children
*/
if ($item['type']==='C') {  // if a checklist, wrap *all* children in a single form ?>
<form action='processItems.php' method='post'>
<?php }

if (!empty($childtype)) foreach (array('n','y') as $comp) foreach ($childtype as $thistype) { //table display loop
    $wasNAonEntry=$AwasNAonEntry[$comp][$thistype];
    $thistableid =$Athistableid[$comp][$thistype] ;
    $wasNAonEntry=$AwasNAonEntry[$comp][$thistype];
    $footertext  =$Afootertext[$comp][$thistype]  ;
    $dispArray   =$AdispArray[$comp][$thistype]   ;
    $maintable   =$Amaintable[$comp][$thistype]   ;
    $noEntries   =$AnoEntries[$comp][$thistype]   ;


    if (count($maintable)) {
        ?>
<div class='reportsection'>
        <?php
		$shownext= ($comp==='n') && ($values['type']==='a' || $values['type']==='w');
		$suppressed=0;
        $trimlength=$_SESSION['config'][($comp==="n")?'trimLengthInReport':'trimLength'];
        if ($comp==='n' && $item['type']!=='C') { ?>
<form action='processItems.php' method='post'><?php
        }
        ?>
<table class='datatable sortable' id='<?php
        echo $thistableid;
?>' summary='table of children of this item'>
        <?php
        if (empty($footertext)) {
            $tfoot='';
        } else {
            $tfoot="<tfoot id='f$thistableid'>\n";
            foreach ($footertext as $line) $tfoot.="<tr><td colspan='4'>\n$line\n</td></tr>\n";
            $tfoot.="</tfoot>\n";
        }
        require 'displayItems.inc.php';
        ?>
</table>
    	<?php
    } else {  // end of: if (count($maintable))
        echo $noEntries;
    }
	if (   ($comp==="n" && (count($maintable)) && $item['type']!=='C')
        || ($item['type']==='C' && $comp!=="n") ) {
	   ?>
<p>
<input type="reset" class="button" />
<input type="submit" class="button" value="Update marked <?php echo getTypes($thistype,$item['type']); ?>s" name="submit" />
<input type='hidden' name='referrer' value='<?php echo "{$pagename}.php?itemId={$values['itemId']}"; ?>' />
        <?php if ($item['type']==='C') { ?>
<button type='submit' name='clearchecklist' id='clearchecklist' value='y'>Clear Checklist</button>
        <?php } else { ?>
<input type="hidden" name="multi" value="y" />
        <?php } ?>
<input type="hidden" name="parentId" value="<?php echo $item['itemId']; ?>" />
<input type='hidden' name='ptype' value='<?php echo $item['type']; ?>' />
<input type='hidden' name='type' value='<?php echo $thistype; ?>' />
<input type="hidden" name="action" value="<?php if ($item['type']==='C') echo 'check'; ?>complete" />
<input type="hidden" name="wasNAonEntry" value='<?php echo implode(' ',$wasNAonEntry); ?>' />
</p>
        <?php
        if ($item['type']!=='C') { ?>
</form>     <?php
        }
    }
    if (count($maintable)) { ?>
</div>  <?php
    }
}  // end of foreach ($completed as $comp) foreach ($childtype as $thistype)
if ($item['type']==='C') { ?>
</form><?php
}
include_once 'footer.inc.php';
?>
