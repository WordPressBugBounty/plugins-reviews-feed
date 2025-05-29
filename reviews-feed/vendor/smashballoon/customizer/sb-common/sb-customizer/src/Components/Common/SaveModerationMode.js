import { __ } from "@wordpress/i18n";
import { useContext, useEffect, useRef } from "react";
import SbUtils from "../../Utils/SbUtils";
import FeedEditorContext from "../Context/FeedEditorContext";
import Button from "./Button";

const SaveModerationMode = ( props ) => {
    const {
        sbCustomizer,
        editorTopLoader,
        editorFeedSettings,
        editorNotification,
        editorFeedData,
        editorModerationCurrentList,
        editorFeedStyling,
        editorModerationMode,
        editorActiveSection
    } = useContext( FeedEditorContext );

    const settingsRef = useRef( editorFeedSettings.feedSettings )
    useEffect(() => {
        settingsRef.current = editorFeedSettings.feedSettings
    }, [ editorFeedSettings.feedSettings ]);


    const saveModerationMode = () => {
        editorActiveSection.setActiveSection( null )
        if( editorFeedSettings.feedSettings.moderationType === 'allow' ){
            editorFeedSettings.setFeedSettings(  {
                ...editorFeedSettings.feedSettings,
                moderationAllowList : [ ...editorModerationCurrentList.moderationCurrentListSelected ]
            }  );
            settingsRef.current.moderationAllowList = [ ...editorModerationCurrentList.moderationCurrentListSelected ]
        }
        else if( editorFeedSettings.feedSettings.moderationType === 'block' ){
            editorFeedSettings.setFeedSettings(  {
                ...editorFeedSettings.feedSettings,
                moderationBlockList : [ ...editorModerationCurrentList.moderationCurrentListSelected ]
            }  );
            settingsRef.current.moderationBlockList = [ ...editorModerationCurrentList.moderationCurrentListSelected ]
        }
        editorModerationMode.setModerationMode( false )


        setTimeout(() => {
            SbUtils.saveFeedData( editorFeedData, editorFeedStyling, settingsRef, sbCustomizer, editorTopLoader, editorNotification,  false, true, true );
        }, 10)
    }

    return (
        <div
            className='sb-savemoderaionmode-buttons'
        >
            {
                props?.disabled === true &&
                <div
                    className='sb-element-overlay'
                    onClick={ () => {
                        if( props?.disabled === true ){
                            editorModerationMode.setModerationMode( false )
                            editorActiveSection.setActiveSection( null )
                        }
                    } }
                ></div>
            }
            <Button
                size='medium'
                type={ props?.disabled === true  ? 'secondary' : 'primary'}
                text={ __( 'Save and Exit', 'sb-customizer' ) }
                icon='success'
                boxshadow={ props?.disabled === true  ? false : true}
                disabled={props?.disabled}
                onClick={ (event) => {
                    if( props?.disabled !== true ){
                        saveModerationMode()
                    }
                } }
            />
            <Button
                size='medium'
                type='secondary'
                boxshadow={ props?.disabled === true  ? false : true}
                text={ __( 'Cancel', 'sb-customizer' ) }
                disabled={props?.disabled}
                onClick={ (event) => {
                    if( props?.disabled !== true ){
                        editorModerationMode.setModerationMode( false )
                        editorActiveSection.setActiveSection( null )
                    }
                } }
            />
        </div>

    )
}

export default SaveModerationMode;