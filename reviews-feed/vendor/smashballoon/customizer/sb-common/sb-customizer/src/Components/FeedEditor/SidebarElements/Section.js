import { __ } from '@wordpress/i18n';
import { useContext } from 'react';
import SbUtils from '../../../Utils/SbUtils'
import FeedEditorContext from '../../Context/FeedEditorContext';

const Section = ( { section, secIndex,  editorActiveSection, editorBreadCrumb, parentSection } ) => {
    const {
            editorModerationMode,
            editorModerationReviews,
            editorFeedData,
            sbCustomizer,
            editorTopLoader,
            editorNotification,
            editorFeedSettings,
            setCurrentCardIndex
        } = useContext( FeedEditorContext );

    const attrList = [
        'dimmed'
    ];

    const startModerationMode = () => {
        editorModerationMode.setModerationMode( true )
        if( editorModerationReviews?.moderationModeReviews.length === 0 ){
            const formData = {
            action : 'sbr_feed_saver_manager_start_moderation_mode',
            feedID : editorFeedData?.feedData?.feed_info?.id,
            previewSettings : SbUtils.stringify( editorFeedSettings.feedSettings ),
            feedName : editorFeedData?.feedData?.feed_info?.feed_name,
        },
        notificationsContent = {
            success : {
                icon : 'success',
                text : __('Moderation Mode Started', 'sb-customizer' )
            }
        }
        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data?.posts ){
                    editorModerationReviews?.setModerationModeReviews( data?.posts );
                }
            },
            editorTopLoader,
            editorNotification,
            notificationsContent
        )
        }
    }

    return (
        <div
            className='sb-customizer-sidebar-sec-el sb-fs'
            data-separator={ section.separator }
            { ...SbUtils.getElementAttributes( section, attrList ) }
            onClick={ () => {
                    if( secIndex === 'moderation_section' ){
                        startModerationMode()
                    }else{
                        editorModerationMode.setModerationMode( false )
                    }
                    setCurrentCardIndex(Math.floor(Math.random() * sbCustomizer?.upsellSidebarCards?.length ))
                    editorActiveSection.setActiveSection( section )
                    if( parentSection !== undefined ){
                        const breadCrumbSection = editorBreadCrumb.breadCrumb !== null ? editorBreadCrumb.breadCrumb.concat( parentSection ) : [ parentSection ];
                        editorBreadCrumb.setBreadCrumb( breadCrumbSection )
                    }
                    if( section?.highlight !== undefined ){
                        window.highlightedSection = section.highlight;
                    }
                }
            }
            key={ secIndex }
        >
            { SbUtils.printIcon( section.icon, 'sb-customizer-sidebar-sec-el-icon' ) }
            <span className='sb-small-p sb-bold sb-dark-text'>{ section.heading }</span>
        </div>
    );
}

export default Section;