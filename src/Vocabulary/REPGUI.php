<?php

  namespace Drupal\rep\Vocabulary;

  class REPGUI {

    /**
     *   The module name for LIST_PAGE is added inside of ListKeywordLanguagePage
     */
    const LIST_PAGE            = "/list/";

    /**
     *   The module name for SELECT_PAGE is added inside of ListManageEmailPage
     */
    const SELECT_PAGE          = "/select/";

    const SELECT_PAGE_BYCONTAINER  = "/selectbycontainer/";
    const SELECT_PAGE_BYSTUDY  = "/selectbystudy/";
    const SELECT_PAGE_BYSOC    = "/selectbysoc/";

    const PROPERTY_PAGE        = "/property/";

    /**
     *   Show the log page of a data file
     */
    const DATAFILE_LOG         = "/rep/log/";

    /**
     *   Show the log page of a data file
     */
    const DATAFILE_DOWNLOAD    = "/rep/download/";

    const DESCRIBE_PAGE        = "/rep/uri/";

    const DOWNLOAD             = "/sir/download/";

    const MANAGE_STUDY         = "/std/manage/managestudy/";

    const DELETE_STUDY         = "/std/manage/deletestudy/";

    const ADD_SEMANTIC_DATA_DICTIONARY   = "/sem/manage/addsemanticdatadictionary/";
    const EDIT_SEMANTIC_DATA_DICTIONARY  = "/sem/manage/editsemanticdatadictionary/";

    const ADD_PROCESS          = "/sir/manage/addprocess/";
    const EDIT_PROCESS         = "/sir/manage/editprocess/";

    const VIEW_STUDY_OBJECTS   = "/std/view/studyobjects/";
    const MANAGE_STUDY_OBJECTS = "/std/manage/studyobjects/";

    const MANAGE_DEPLOYMENTS   = "/dpl/manage/deployments/";
    const MANAGE_STREAMS       = "/dpl/manage/streams/";

    const ADD_TASK             = "/sir/manage/addtask/";
    const EDIT_TASK            = "/sir/manage/edittask/";

    const ADD_PROJECT          = "/meugrafo/manage/addproject/";
    const EDIT_PROJECT         = "/meugrafo/manage/editproject/";

  }
