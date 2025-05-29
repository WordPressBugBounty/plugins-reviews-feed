import { __ } from '@wordpress/i18n'
import { useContext } from 'react'
import AboutusScreenContext from '../Components/Context/AboutusScreenContext';
import DashboardScreenContext from '../Components/Context/DashboardScreenContext';
import FeedEditorContext from '../Components/Context/FeedEditorContext'
import SettingsScreenContext from '../Components/Context/SettingsScreenContext';
import SupportScreenContext from '../Components/Context/SupportScreenContext';
import CollectionsScreenContext from '../Components/Context/CollectionsScreenContext';

class SbUtils  {

    /**
     *   Generates : CSS Class Names
    */
    static getClassNames = ( props, slug, classesList ) => {
        let classes = [ slug, props.customClass ];
        classesList.forEach(function( classItem ) {
            if( typeof classItem === 'object' ){
                classes.push(slug + '-' + (props[Object.keys(classItem)?.[0]] === undefined ? Object.values(classItem)?.[0] : props[Object.keys(classItem)?.[0]]));
            }
            if( props[classItem] !== undefined ){
                classes.push(slug + '-' + props[classItem]);
            }
        });
        return classes.join(' ');
    }

    /**
     *   Generates : Element HTML Attibutes
    */
    static getElementAttributes = ( props, attributesList, ref ) => {
        let attrs = [];
        attributesList.forEach(function( attrItem ) {
            if( typeof attrItem === 'object' ){
                attrs[`data-${Object.keys(attrItem)?.[0]}`] = props[Object.keys(attrItem)?.[0]] === undefined ? Object.values(attrItem)?.[0] : props[Object.keys(attrItem)?.[0]];
            }
            else if( props[attrItem] !== undefined && props[attrItem] !== null ){
                if( attrItem === 'disabled' ){
                    attrs[`${attrItem}`] = props[attrItem];
                }else{
                    attrs[`data-${attrItem}`] = props[attrItem];
                }
            }
        });
        return attrs;
    }

    static goToLink = ( link, target  ) => {
        return () => {
            window.open(link, target);
        }
    }
    /**
     *  Generates : Element React Actions
    */
    static getElementActions = ( props, actionsList, ref ) => {
        if( props?.link ){
            return {
                "onClick" : SbUtils.goToLink( props?.link, props?.target || '_self' )
            }
        }else{
            let attrs = [];
            actionsList.forEach(function( actItem ) {
                if( props[actItem] !== undefined ){
                    attrs[`${actItem}`] = props[actItem];
                }
            });
            return attrs;
        }
    }

    /**
     *   Check if Element is Empty/Undefined/Null
    */
    static checkNotEmpty = ( value ) => {
        return value !== undefined && value !== 'undefined' && value !== null &&  value?.toString()?.replace(/ /gi,'') !== '';
    }

    /**
     *   Ajax Post Action
    */
    static ajaxPost = ( ajaxHandler, data, callback, topLoader = null, editorNotification = null, notificationsContent = null ) => {

        //Set Form Data body for the Ajax call
        var formData = new FormData();
        for ( var key in data ) {
            formData.append(key, data[key]);

        }
        if( data['nonce'] === undefined  ){
            formData.append( 'nonce', window?.sb_customizer?.nonce );
        }

        topLoader !== null && topLoader.setLoader( true ); //Show top Bar header loader

        fetch( ajaxHandler, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        })
        .then(response => response.json())
        .then( ( data ) => {

            callback( data ); //CallBack Function
            topLoader !== null && topLoader.setLoader( false ); //Hide top Bar header loader

            //Show Success Notification if Set
            if (
                data?.success === false
                && data?.message !== undefined
            ) {
               setTimeout(() => {
                 SbUtils.applyNotification({
                    type : 'error',
                    icon : 'info',
                    text : data?.message
                }, editorNotification);
               }, 50);
                return false;
            } else {
                ( editorNotification !== null && notificationsContent?.success )
                && SbUtils.applyNotification( notificationsContent.success , editorNotification );
            }


        } ).catch(error => {
            //Show Error Notification if Set
            topLoader !== null && topLoader.setLoader( false ); //Hide top Bar header loader
            ( editorNotification !== null && notificationsContent?.error )
            && SbUtils.applyNotification( notificationsContent.error , editorNotification );

        })
	}


    /**
     *   Notification Process
     *
    */
    static applyNotification = ( notification, editorNotification ) => {
        if (SbUtils.checkNotEmpty(notification?.text)) {
            editorNotification.setNotification({
                active : true,
                type : notification?.type,
                icon : notification?.icon,
                text : notification?.text
            });
            setTimeout(function(){
                editorNotification.setNotification({
                    active : false,
                    type : null,
                    icon : null,
                    text : null
                });
            }, notification?.time ? notification?.time : 3000);
        }
    }

    /**
     *   Checks if CSS element is missing unit then add it
    */
    static addCssUnit = ( value ) => {
        const units = ['px', 'em', '%', 'vh', 'vw' ];
        return units.some(el => value.includes( el )) ? value : `${ SbUtils.checkNotEmpty( value ) ? value : 0}px` ;
    }

    /**
     *   Find Element By ID
    */
    static findElementById = ( objects, searchBy, value ) => {
        let [ objkey, obj ] = Object.entries(objects).find(([key, ob]) => ob[searchBy] === value)
        return obj;
    }

    /**
     *   Find Nested Element
    */
    static findNestedElement = ( objects, searchBy, value ) => {
        if( typeof objects === 'object'){
            const objectResult = objects?.find( (elem, ind) => {
                if( elem[searchBy] === value )
                    return elem;
                else{
                    let newElSearch = elem?.sections !== undefined ?
                                        Object.entries(elem.sections) : elem;

                    if(typeof elem === 'string'){
                        newElSearch = Object.entries(objects[1]?.controls) ? Object.entries(objects[1]?.controls) : {}
                    }
                    return SbUtils.findNestedElement(newElSearch, searchBy, value)
                }
            });
            return objectResult;
        }
        return false;
    }



    /**
     *   Prints SVG Icon
    */
    static printIcon = ( iconName, customClass = false, key = false, iconSize = undefined ) => {
        const iconStyle = (iconSize !== undefined && { width : iconSize + 'px'  } ) || null;

        return window?.sb_customizer?.iconsList[iconName] ?
               <span
                key={key !== false ? key : key}
                className={ ( customClass !== false ? customClass : '' ) + ( iconSize !== undefined ? ' sb-custom-icon-size' : '') }
                style={ iconStyle }
                dangerouslySetInnerHTML={{__html: window.sb_customizer.iconsList[iconName] }}></span>
                : '';
    }

    /**
     *  Check Control Conditions Hide/Show/Dimmed
    */
    static checkControlCondition = ( element, feedSettings ) => {
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro? true : false;
        if(element?.hidePro === true && isPro !== true){
            return false
        }else{
            if ( element?.condition === undefined ){
                return true;
            } else {
                let isConditionTrue = 0;
                Object.keys( element.condition ).map( (condition, index) => {
                    if(element.condition[ condition ].indexOf( feedSettings[ condition ] ) !== -1)
                        isConditionTrue += 1
                });
                let showElement = isConditionTrue === Object.keys(element.condition).length;
                return showElement === false && element?.conditionDimmed === true ? 'dimmed' : showElement;

            }
        }

    }

    /*
        Get Feed Styling
    */
    static getFeedStyling = ( feedSettings, customizerData, feedID ) => {
        let styles = '';
        customizerData.forEach( ( tab ) => {
            Object.values( tab.sections ).map( ( section ) => {
                styles += SbUtils.getFeedStylingRecursive(section, feedSettings, feedID);
            })
        })
        return styles;
    }


    /*
        Get Feed Styling Recursive
    */
    static getFeedStylingRecursive = ( element, feedSettings, feedID) => {
        let styles = '';
        element?.controls.forEach(function ( el ) {
            let controlStyle = SbUtils.getSingleControlStyle( el, feedSettings, feedID );
            styles += controlStyle !== false ? controlStyle : '';

            //Nested List Elements
            if( el?.controls !== undefined )
                styles += SbUtils.getFeedStylingRecursive(el, feedSettings, feedID)
        });
        return styles;
    }

    /*
        Get Single Control Style
    */
    static getSingleControlStyle = ( control, feedSettings, feedID ) => {
        let applyStyle = SbUtils.checkControlCondition( control, feedSettings );
        if( control?.style === undefined || applyStyle === false)
            return false

        let styleString = '';
        let containerFeedId = '#sb-reviews-container-' + feedID ;
        Object.entries( control.style ).map( ( css ) => {
            let cssValue = SbUtils.createCssStyle( control.type, feedSettings[control?.id] );
            styleString += (cssValue !== null && cssValue !== undefined) ? `${containerFeedId + ' ' + css[0]}{${css[1].replace("{{value}}", cssValue)}}` : '';
        })
        return styleString;
    }

    /*
     *   Create Style
     *   This Will dump the CSS style depending on the Type
    */
    static createCssStyle = ( type, value ) => {

        switch (type) {

            // Create Box Shadow Styling
            case 'boxshadow':
                if( value?.enabled === undefined || value?.enabled === false )
                    return null;
                return `${SbUtils.addCssUnit( value.x )} ${SbUtils.addCssUnit( value.y )} ${SbUtils.addCssUnit( value.blur )} ${SbUtils.addCssUnit( value.spread )} ${value.color}`;

            // Create Box Radius Styling
            case 'borderradius':
                if( value?.enabled === undefined || value?.enabled === false )
                    return null;
                return value.radius + 'px';

             // Create Stroke Styling
            case 'stroke':
                if( value?.enabled === undefined || value?.enabled === false )
                    return null;
                return value.thickness + 'px solid '+ value.color;

            // Create Distance Styling : Margins/Paddings
            case 'distance':
                let sidesList = [ 'top', 'right', 'bottom', 'left'],
                    distances = '';
                sidesList.forEach( side => {
                    distances += SbUtils.checkNotEmpty( value[side] ) ? `${value[side]}px ` : '0px ';
                });
                return distances;

            // Create Font Styling : Family/Weight/Size/height
            case 'font':
                let fontElements = [ 'family', 'weight', 'size', 'height'],
                    fonts = '';
                fontElements.forEach( f => {
                    let includeFont = SbUtils.checkNotEmpty( value[f] ) && value[f] !== 'inherit' ;
                    fonts += includeFont ? `${f === 'height' ? 'line' : 'font' }-${f}:${value[f]}${f === 'size' ? 'px' : '' };` : '';
                });
                return fonts;

            default:
                return value;
        }
    }

    /*
     *   Transform Date & Print Date
    */
    static printDate = ( postDate, feedSettings, sbCustomizer ) => {
        let originalDate 	= postDate,
			dateOffset 		= new Date(),
			offsetTimezone 	= dateOffset.getTimezoneOffset(),
            translatedText = window.sb_customizer?.pluginSettings?.translations,
			periods = [
			    translatedText?.second ? translatedText?.second : __('second', 'sb-customizer'),
				translatedText?.minute ? translatedText?.minute : __('minute', 'sb-customizer'),
                translatedText?.hour ? translatedText?.hour : __('hour', 'sb-customizer'),
                translatedText?.day ? translatedText?.day : __('day', 'sb-customizer'),
                translatedText?.week ? translatedText?.week : __('week', 'sb-customizer'),
                translatedText?.month ? translatedText?.month : __('month', 'sb-customizer'),
                translatedText?.year ? translatedText?.year : __('year', 'sb-customizer')
			],
			periodsPlural = [
				translatedText?.seconds ? translatedText?.seconds : __('seconds', 'sb-customizer'),
				translatedText?.minutes ? translatedText?.minutes : __('minutes', 'sb-customizer'),
				translatedText?.hours ? translatedText?.hours : __('hours', 'sb-customizer'),
				translatedText?.days ? translatedText?.days : __('days', 'sb-customizer'),
				translatedText?.weeks ? translatedText?.weeks : __('weeks', 'sb-customizer'),
				translatedText?.months ? translatedText?.months : __('months', 'sb-customizer'),
				translatedText?.years ? translatedText?.years : __('years', 'sb-customizer')
			],
			lengths		 = ["60","60","24","7","4.35","12","10"],
            now 		= dateOffset.getTime()  / 1000,
            newTime 	= originalDate + offsetTimezone,
            printDate 	= '',
            dateFortmat = feedSettings.dateFormat,
            agoText 	= translatedText?.ago ? translatedText?.ago : __('ago', 'sb-customizer'),
            difference 	= null,
            formatsChoices = {
                '2' : 'F jS, g:i a',
                '3' : 'F jS',
                '4' : 'D F jS',
                '5' : 'l F jS',
                '6' : 'D M jS, Y',
                '7' : 'l F jS, Y',
                '8' : 'l F jS, Y - g:i a',
                '9' : "l M jS, 'y",
                '10' : 'm.d.y',
                '11' : 'm/d/y',
                '12' : 'd.m.y',
                '13' : 'd/m/y',
                '14' : 'd-m-Y, G:i',
                '15' : 'jS F Y, G:i',
                '16' : 'd M Y, G:i',
                '17' : 'l jS F Y, G:i',
                '18' : 'm.d.y - G:i',
                '19' : 'd.m.y - G:i'
            };
			if( formatsChoices[dateFortmat] !== undefined ){
			    printDate = window.date_i18n( formatsChoices[dateFortmat], newTime );
			}else if(dateFortmat === 'custom'){
			    let dateCustom = feedSettings.dateCustomFormat;
				try{
                    printDate = window.date_i18n( dateCustom , newTime );
                }catch(error){
                    printDate = __('Unsported Format', 'sb-cutomzier');
                }

			}
			else{
			    if( now > originalDate ) {
	                difference = now - originalDate;
				}else{
	                difference = originalDate - now;
				}
				for(var j = 0; difference >= lengths[j] && j < lengths.length-1; j++) {
	              	difference /= lengths[j];
	            }
	            difference = Math.round(difference);
	            if(difference !== 1) {
		            periods[j] = periodsPlural[j];
		        }
				printDate = difference + " " + periods[j] + " "+ agoText;
			}
		return printDate;
	}

    /*
     *   Print Post Text + Trim
    */
    static printText = ( postText, feedSettings ) => {
        let text = postText,
            contentLength = feedSettings.contentLength;

        if( SbUtils.checkNotEmpty(postText) && text.length > contentLength ){
            return (
                <>
                    {text.substring(0, contentLength)}
                    <a className='sb-post-readmorelink'>
                        ...
                    </a>
                </>

            )
        }else{
            return (
                <>
                    {postText}
                </>
            )
        }
    }

     /*
     *   Recursive function to add more Highlighted Sections
     *
    */
    static getHighlightedSectionRecursive = ( element ) => {
        let highlightedSection = {};
        element?.controls.forEach(function ( el ) {
            if( el?.highlight !== undefined ){
                highlightedSection[ el?.highlight ] = el;
            }
            //Nested List Elements
            if( el?.controls !== undefined ){
                highlightedSection = {
                    ...highlightedSection,
                    ...SbUtils.getHighlightedSectionRecursive( el )
                }
            }
        });
        return highlightedSection;
    }

    /*
     *   Create Object for Highlighted Sections
     *
    */
    static getHighlightedSection = ( customizerData ) => {
        let highlightedSection = {};
        customizerData.forEach( ( tab ) => {
            Object.values( tab.sections ).map( ( section ) => {
                if( section?.highlight !== undefined ){
                    highlightedSection[ section?.highlight ] = section;
                }
                highlightedSection = {
                    ...highlightedSection,
                    ...SbUtils.getHighlightedSectionRecursive( section )
                }
            })
        })

        return highlightedSection;
    }

    /*
     *   Print Section Highlighter
     *   This will add the blue highlight for the Customizer Preview Sections
    */
    static addHighlighter = ( id, text, index = 0, isParent = false) => {
        const { editorActiveSection, editorFeedHighlightedSection, editorHighlightedSection } = useContext( FeedEditorContext );
        return (
            <div
                className='sb-preview-highlight-ctn sb-tr-1'
                data-active={window.highlightedSection === id && index === 0}
                data-dimmed={editorHighlightedSection.hoveredHighlightedSection !== id && editorHighlightedSection.hoveredHighlightedSection !== null ? 'true' : 'false'}
                data-hover={editorHighlightedSection.hoveredHighlightedSection === id && window.highlightedSection !== id}
                data-isparent={isParent}
                onMouseEnter={ () => {
                    editorHighlightedSection.setHoveredHighlightedSection(id);
                } }
                onMouseLeave={ () => {
                    editorHighlightedSection.setHoveredHighlightedSection(null);
                } }
                onClick={ () => {
                    if( editorFeedHighlightedSection[id] !== undefined){
                        editorActiveSection.setActiveSection( editorFeedHighlightedSection[id] )
                    }
                }}
            >
                <span
                    className='sb-preview-highlight-txt'
                    dangerouslySetInnerHTML={{__html:  text.replace(/\s/g, '&nbsp;')  }}
                >
                </span>
            </div>
        )
    }

    /*
     *   Creates a tooltip & Display it when hover
     *
    */
    static printTooltip = ( text, defaults = {} ) => {
        let args = {
            type : defaults.type ?? 'default',
            position : defaults.position ?? 'top-center',
            textAlign : defaults.textAlign ?? 'left',
            width : defaults.width ?? 'default',
            replaceText : defaults.replaceText ?? true
        }
        return (
            <div
                className='sb-tooltip'
                data-type={ args.type }
                data-position={ args.position }
                data-width={ args.width }
                data-textalign={ args.textAlign }
                dangerouslySetInnerHTML={{__html:  args.replaceText ? text.replace(/\s/g, '&nbsp;') : text }}
            >
            </div>
        )
    }

    /*
     *   Creates a tooltip & Display it when hover
     *
    */
    static copyToClipBoard = ( text, editorNotification ) => {
        SbUtils.applyNotification( {
            icon : 'success',
            text : __( 'Copied to Clipboard', 'sb-customizer' )
        }, editorNotification )
        const el = document.createElement('textarea');
		el.className = 'sb-copy-clpboard';
		el.value = text;
		document.body.appendChild(el);
	    el.select();
		document.execCommand('copy');
		document.body.removeChild(el);
    }

    /*
     *   Stringify JSON Objects
     *
    */
    static stringify = ( obj ) => {
        return JSON.stringify(obj, (key, value) => {
            if ( ! isNaN( value ) && ! Array.isArray( value ) && typeof value !== "boolean" && typeof value !== "string" ){
                value = Number(value)
            }
            return value
        })
    }


    /*
     *   Check & Parse JSON String
     *
    */
    static jsonParse = (jsonString) => {
		try {
		    return JSON.parse(jsonString);
		} catch(e) {
		    return false;
		}
	}

    static upgradePlanAction = () => {
        window.open("https://smashballoon.com/reviews-feed/?utm_campaign=reviews-free&utm_source=lite-upgrade-bar")
    }

    static openAddApiKeyModal = (apiKeyModal, provider) => {
        apiKeyModal.setAddApiKeyModal({
            active : true,
            provider : provider
        })
    }

    static getLastUpdatedNoticeBar = () => {
        const firstSource = window?.sb_customizer?.feedData?.sourcesList[0];
        if (firstSource?.last_updated !== undefined) {
            try{
                let originalDate 	= Date.parse(firstSource?.last_updated)  / 1000,
                dateOffset 		= new Date(),
                offsetTimezone 	= dateOffset.getTimezoneOffset(),
                newTime 	= originalDate + offsetTimezone
                return 'Last updated on ' + window.date_i18n('j M, Y', newTime );
            }catch{
                return false;
            }
        }
        return false;
    }

    /**
     * Apply Free Retrieval Notice
     */
    static freeRetrievalApplyTopNoticeBar = (apis, isPro, freeRet, topNoticeBar, provider) => {
        const date = SbUtils.getLastUpdatedNoticeBar();
        const heading = isPro
            ?  __('Feeds are updating once a week. To update them more often, add an API key.', 'reviews-free')
            : (date !== false ? date + ' ' : '') + __('To fetch new reviews, add an API key or Upgrade your plan.', 'reviews-free');

        const actionsList = isPro
            ?  [
                {
                    text : __('Add an API Key.', 'reviews-free'),
                    style : 'bg',
                    provider : provider,
                    onClick : 'openAddApiKeyModal'
                }
            ]
            :
            [
                {
                    text : __('Upgrade Plan', 'reviews-free'),
                    style : 'bg',
                    onClick : SbUtils.upgradePlanAction
                },
                {
                    text : __('Add an API Key.', 'reviews-free'),
                    provider : provider,
                    onClick :  'openAddApiKeyModal'
                }
            ];


        let noticeBarObj = {
            apiKeyNotice : {
                heading : heading,
                actionsList : actionsList,
                active : topNoticeBar?.apiKeyNotice?.active !== undefined ? topNoticeBar?.apiKeyNotice?.active : true,
                type: isPro ? 'green' : 'important'
            }
        };


        return noticeBarObj;
    }

    /*
     * Checking API Keys for the Notice Bar
     * Nabil should check here
    */
    static checkAPIKeys = ( noticeBar, topNoticeBar, apis, feedData, apiKeyModal, apiLimits, isPro, freeRet ) => {
        let newNoticeBar = {};
        let noApiKeyNeeded = ['facebook','trustpilot', 'wordpress.org', 'collection'];

        if( feedData?.sourcesList && feedData.sourcesList.length > 0){
            feedData.sourcesList.forEach( src => {

                //Check Free Retrieval API Key Since it will have diferent message
                if (
                    freeRet?.freeRetrieverData?.providers
                    && !SbUtils.checkAPIKeyExists(src.provider, apis)
                    && freeRet?.freeRetrieverData?.providers.includes(src.provider)
                ) {
                    newNoticeBar = SbUtils.freeRetrievalApplyTopNoticeBar(apis, isPro, freeRet, topNoticeBar, src.provider);
                } else {
                    //Check Needed API Key
                    if(
                        !noApiKeyNeeded.includes(src.provider)
                        && !SbUtils.checkAPIKeyExists(src.provider, apis)
                    ){
                        let apiKeyNoticeBar = {
                            apiKeyNotice : {
                                heading : __('You need to add an API key to fetch new reviews', 'sb-customizer'),
                                active : topNoticeBar?.apiKeyNotice?.active !== undefined ? topNoticeBar?.apiKeyNotice?.active : true ,
                                actionText : __('Add API Key', 'sb-customizer'),
                                type: apiLimits?.apiKeyLimits?.includes(src.provider) ? 'error' : 'important'
                            }
                        }
                        apiKeyNoticeBar.apiKeyNotice.actionClick = () => {
                            apiKeyModal.setAddApiKeyModal({
                                active : true,
                                provider : src.provider
                            })
                        }
                        newNoticeBar = apiKeyNoticeBar;
                    }
                }


            } )
        }

        if( isPro === false ){
            newNoticeBar = {
                ...newNoticeBar,
                upgradeProNotice : {
                    heading : __('You\'re using Reviews Feed Lite. To unlock more features consider', 'sb-customizer'),
                    active : true ,
                    actionText : __('upgrading to Pro', 'sb-customizer'),
                    type:  'default',
                    close: false,
                    actionClick : () => {
                        window.open("https://smashballoon.com/reviews-feed/?utm_campaign=reviews-free&utm_source=lite-upgrade-bar")
                    }
                }
            }
        }
        return  {
            ...noticeBar,
            topNoticeBar :{
                 ...topNoticeBar,
                ...newNoticeBar
            }
        };

    }


    /*
     *   Get Current Context
     *
    */
    static getCurrentContext = () => {
        let currentContext = window.sb_customizer.isFeedEditor ? FeedEditorContext : DashboardScreenContext;
        if( window.sb_customizer.reactScreen === 'settings'  ){
            currentContext = SettingsScreenContext;
        }
        if( window.sb_customizer.reactScreen === 'aboutus'  ){
            currentContext = AboutusScreenContext;
        }
        if( window.sb_customizer.reactScreen === 'support'  ){
            currentContext = SupportScreenContext;
        }
        if( window.sb_customizer.reactScreen === 'collections'  ){
            currentContext = CollectionsScreenContext;
        }
        return currentContext;
	}

    /*
     *   Export JSON to File
    */
    static exportStringToFile = ( content, filename, type = "application/json" ) => {
        const element = document.createElement("a");
        const file = new Blob([content], {
            type,
        });
        element.href = URL.createObjectURL(file);
        element.download = filename;
        document.body.appendChild(element);
        element.click();
    }

    static  feedFlyPreview = ( editorFeedData, editorTopLoader, editorNotification, sbCustomizer, settingsRef ) => {
        const formData = {
            action : 'sbr_feed_saver_manager_fly_preview',
            feedID : editorFeedData?.feedData?.feed_info?.id,
            previewSettings : SbUtils.stringify( settingsRef.current ),
            feedName : editorFeedData?.feedData?.feed_info?.feed_name,
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __('Preview updated successfully', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data?.posts ){
                    editorFeedData.setFeedData(  {
                        ...editorFeedData.feedData,
                        posts : data?.posts,
                        sourcesList : data?.sourcesList
                    } );
                }
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }

    static convertDate = ( dateString ) => {
        const theDate = new Date( dateString );
        return theDate.getTime() / 1000;
    }


    //Save Feed Data
    static saveFeedData = ( editorFeedData, editorFeedStyling, editorFeedSettings, sbCustomizer, editorTopLoader, editorNotification,  exit = false, isSettingRef = false, getPosts = false ) => {
        const formData = {
            action : 'sbr_feed_saver_manager_builder_update',
            update_feed	: true,
            feed_id : editorFeedData.feedData.feed_info.id,
            feed_name : editorFeedData.feedData.feed_info.feed_name,
            feed_style :editorFeedStyling,
            settings : SbUtils.stringify( isSettingRef ? editorFeedSettings.current : editorFeedSettings.feedSettings ),
            get_posts : getPosts
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __('Feed saved succesfully', 'sb-customizer')
            }
        }

        if( SbUtils.checkNotEmpty( localStorage.getItem('newCreatedFeed') ) ){
            editorTopLoader = null;
            editorNotification = null;
        }

        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( getPosts === true ){
                    if( data?.posts ){
                        editorFeedData.setFeedData(  {
                            ...editorFeedData.feedData,
                            posts : data?.posts
                        } );
                    }
                }
                if(exit === true){
                    window.location.href = sbCustomizer.builderUrl;
                }
                localStorage.removeItem('newCreatedFeed')
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
    }

    //Update Array
    static updateArray = ( id, initialArray ) => {
        let newArray = [...initialArray];
        if( ! newArray.includes( id ) ){
            newArray.push( id )
        }else{
            newArray.splice( newArray.indexOf( id ), 1 );
        }
        return newArray;
    }

    /**
     *   Checks if the settings is PRO
     *   then it Opens a Modal
    */
    static checkSettingIsPro = ( upsellModal ) => {
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro ? true : false;

        if((upsellModal === undefined && isPro !== true) || isPro){
            return false;
        }
        return upsellModal;
    }

    /**
     *   Open Upsell Modal
     *
    */
    static openUpsellModal = ( upsellModalType, upsellModal ) => {
        upsellModal.setUpsellActive( upsellModalType )
    }

    /*
     *   Prints Pro Label
     *
    */
    static printProLabel = ( isPro ) => {
        if(isPro){
            return (
                <span className='sb-pro-label'>PRO</span>
            )
        }
    }


    /*
     *   Convert Date for Input display
     *
    */
    static converDate = ( date ) => {
		const dateTime = new Date(date * 1000);
		if (!SbUtils.checkNotEmpty(date) || isNaN(dateTime)) {
			return date;
		}
		return dateTime.toISOString().slice(0,16);
	}

    /*
     *   Get Collection Connected Forms
     *
    */
    static getCollectionConnectedForms = ( collection ) => {
        if (collection?.info === undefined) {
            return []
        }
		const info = Array.isArray(collection?.info) ?
                collection?.info :
                SbUtils.jsonParse(collection?.info.replace(new RegExp("\\\\", "g"), ""));

		return info?.connected_forms || [];
	}

    /*
     *   Get Collection Connected Forms
     *
    */
    static getSubmissionReviewData = ( submission ) => {
        const jsonData      = SbUtils.jsonParse(submission?.json_data),
                usedIn      =   SbUtils.jsonParse(submission?.used_in),
                ArchivedIn  =   SbUtils.jsonParse(submission?.archived_in),
                deletedIn   =   SbUtils.jsonParse(submission?.deleted_in);

        return {
            id          : submission?.id,
            submissonId : submission?.submission_id,
            title       : jsonData?.title,
            fullName    : jsonData?.full_name,
            text        : submission?.content,
            rating      : submission?.rating,
            date         : submission?.date,
            usedIn      : usedIn,
            ArchivedIn  : ArchivedIn,
            deletedIn   : deletedIn,
            form        : {
                id :    submission?.form_id,
                plugin : submission?.plugin
            }
        }
    }

    /*
     *  Check Next Submissions Pagination
     *
    */
    static checkNextPaginationButton = ( currentPage, fullCount ) => {
        return  ((currentPage + 1) * 50) < fullCount;
    }

    /*
     *  Check Previous Page
     *
    */
    static checkPreviousPaginationButton = ( currentPage, fullCount ) => {
        return  currentPage >= 1;
    }

     /*
     *  Check Next Submissions Pagination
     *
    */
    static paginationText = ( currentPageCount, fullCount ) => {
        return  fullCount + ' ' + __( 'Reviews', 'reviews-feed' )
        //return  currentPageCount + ' ' + __( 'Of', 'reviews-feed' ) + ' ' + fullCount + ' ' + __( 'Reviews', 'reviews-feed' )
    }

    /*
     *  Open Submission Details
     *
    */
    static openSubmissionDetails =( submission, sbCustomizer ) => {
        const replace = submission.plugin + '-' + submission.form_id + '-';
        const entryId = submission.submission_id.replace(replace, '');

        let editURL = '';
        switch (submission.plugin) {
            case 'wpforms':
                editURL = sbCustomizer.adminHomeURL + '?page=wpforms-entries&view=details&entry_id=' + entryId;
                break;
            case 'formidable':
                editURL = sbCustomizer.adminHomeURL + '?page=formidable&frm_action=edit&id=' + entryId;
                break;
            case 'edd':
                editURL = sbCustomizer.editHomeURL + '?post_type=download&page=edd-reviews&action=edit&edit=true&r=' + entryId;
                break;
        }
        window.open(editURL , '_blank')
    }

    /*
     * Check if Email is Verified
     *
    */
    static isEmailVerified = (freeRet) =>  {
        //Nabil you should CHeck this
        return freeRet?.freeRetrieverData?.isEmailVerified === true;
    }

    /*
     * Check if Email is Verified
     *
    */
    static freeUserWithNoAPIKeysNoEmail = (apis, freeRet) =>  {
        return !SbUtils.checkNotEmpty(apis?.apiKeys['google'])
                && !SbUtils.checkNotEmpty(apis?.apiKeys['yelp'])
                && !SbUtils.isEmailVerified(freeRet)
    }

    /*
     * Init the first screen of
     * Add  Source Modal
    */
    static initAddSourceModalScreen = (apis, freeRet) => { // addSource, freeRetriever
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro
            ? true : false;

        let screen = 'addSource';

        //Free & both Yelp + Google are empty
        if (!isPro) {
            return SbUtils.freeUserWithNoAPIKeysNoEmail(apis, freeRet)
                ? 'freeRetriever'
                : 'addSource';
        }

        return screen;
    }

    /*
     * Init the first screen of
     * Retrieve Modal Screen
    */
    static initRetrieveModalScreen = (apis, freeRet) => { // verifyEmail - sourceAdded - limitExceeded - addApiKey
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro
            ? true : false;

        let screen = 'addApiKey';
        //Free & both Yelp + Google are empty
        if (!isPro) {
            screen = SbUtils.freeUserWithNoAPIKeysNoEmail(apis, freeRet)
                ? 'verifyEmail'
                : 'limitExceeded';
        } else {
            return 'limitExceeded';
        }
        return screen;
    }

     /**
     * Should Free User in Case Email is Not Valid
     * Google & Yelp
     *
     * @return ScreenType string
     */
    static shouldLimiFreeUserEmail = (provider, apis, freeRet) => {
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro
            ? true : false;

        if (!isPro) {
            let isEmailVerified = SbUtils.isEmailVerified(freeRet),
                currentProviderApiKey   =  SbUtils.checkAPIKeyExists(provider, apis);
            return !currentProviderApiKey && !isEmailVerified;
        }
        return false;
    }

    /**
     * Check for Free source limit
     * Google & Yelp
     *
     * @return boolean
     */
    static shouldLimiFreeRetrieval = (provider, apis, freeRet) => {
        let isPro = window?.sb_customizer?.isPro && window?.sb_customizer?.isPro
            ? true : false;

        let googleSourceNumber      = freeRet?.freeRetrieverData?.providerInfo?.google?.sourcesNumber ?? 0,
            yelpSourceNumber        = freeRet?.freeRetrieverData?.providerInfo?.yelp?.sourcesNumber ?? 0,
            currentProviderApiKey   =  SbUtils.checkAPIKeyExists(provider, apis);

        /**
         * For Free Users In Case There is NO API KEY
        */
        if (!isPro) {
            return googleSourceNumber >= 1 || yelpSourceNumber >= 1;
        } else {
            //Check Provider and the number of Connected Sources.
            if (provider === 'google') {
                return googleSourceNumber >= 2;
            }
            if (provider === 'yelp') {
                return yelpSourceNumber >= 5;
            }
        }
    }

    /**
     * Check API Key Exists
     * Google & Yelp
     */
    static checkAPIKeyExists = (provider, apis) => {
        return undefined !== apis?.apiKeys[provider] && SbUtils.checkNotEmpty(apis?.apiKeys[provider]);
    }

    /*
     *  Check Add Source Modal
     *
    */
    static getAddSourceModalSize = (isSmall = true) => {
        return window.fbConnectProcess === true || isSmall === true ? 'small' : 'medium';
    }

    /*
     *  Parse ISO date
     *
    */
    static parseISODate = (isoDate) => {
        return isoDate.replace('T', ' at ').substring(0, 19);
    }

    /**
     * Check the Forms Data Manager
     * @param {*} formsManagerData
     */
    static checkInstalledForms = (formsManagerData) => {
        let dataPlugins = {};
        formsManagerData.forEach((form, fromIndex) => {
            dataPlugins[form.info.id] = {
                plugin : form.info.id,
                installed : form.info.is_installed,
                active : form.info.is_active,
                freeVersion : form.freeVersion
            };
        });
        return dataPlugins;
    }

    /**
     * Check the Forms Data Manager
     * @param {*} formsManagerData
     */
    static checkSingleForm = (form, formData) => {
        return formData[form]?.active === true
            || formData[form]?.freeVersion === true;
    }

}
export default SbUtils;