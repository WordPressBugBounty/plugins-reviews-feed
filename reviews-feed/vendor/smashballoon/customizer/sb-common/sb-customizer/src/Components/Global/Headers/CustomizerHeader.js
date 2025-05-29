import { __ } from "@wordpress/i18n";
import { useContext, useState } from "react";
import SbUtils from "../../../Utils/SbUtils";
import Button from "../../Common/Button";
import Input from "../../Common/Input";
import FeedEditorContext from "../../Context/FeedEditorContext";
import CustomizerEmbedModal from "../../Modals/CustomizerEmbedModal";
import ModalContainer from "../ModalContainer";

const CustomizerHeader = ( props ) => {
    const {
        editorActiveViews,
        feedDataInitial,
        editorFeedData,
        editorFeedSettings,
        editorNotification,
        sbCustomizer,
        editorConfirmDialog,
        editorFeedStyling,
        headerClasses
    } = useContext( FeedEditorContext ) ;

    const editorTopLoader = props.editorTopLoader;

    const [ embedModal, setEmbedModal ] = useState( false );

    const saveUpdateHeader = ( exit = false ) => {
        SbUtils.saveFeedData( editorFeedData, editorFeedStyling, editorFeedSettings, sbCustomizer, editorTopLoader, editorNotification,  exit );
    }

    //Back Button Click
    const backButtonClick = () => {
       const checkSettingChanges = JSON.stringify(feedDataInitial.settings) !== JSON.stringify(editorFeedSettings.feedSettings);
       if( checkSettingChanges === true){
           const confirmDialogInfo = {
                   active : true,
                   heading : __('Are you Sure?', 'sb-customizer'),
                   description : __( 'Are you sure you want to leave this page, all unsaved settings will be lost, please make sure to save before leaving.', 'sb-customizer' ),
                   confirm : {
                       text : __('Save and Exit', 'sb-customizer'),
                       type : 'primary'
                   },
                   cancel : {
                       text : __('Exit without Saving', 'sb-customizer'),
                       type : 'destructive'
                   }
          }
           confirmDialogInfo.confirm.onConfirm = () => {
               saveUpdateHeader( true )
           }
           confirmDialogInfo.cancel.onCancel = () => {
               window.location.href = sbCustomizer.builderUrl;
           }
           editorConfirmDialog.setConfirmDialog( confirmDialogInfo )
       }else{
            window.location.href = sbCustomizer.builderUrl;
       }
    }

    return (
        <>
            <Button
                type='secondary'
                size='medium'
                icon='close'
                text={ __( 'Close Editor', 'sb-customizer' ) }
                customClass='sb-close-btn'
                onClick={ () => {
                    backButtonClick()
                } }
            />
            <div className='sb-header-feedname-ctn'
                data-edit={editorActiveViews.activeViews?.headerInput}
            >
                {
                    editorActiveViews.activeViews?.headerInput &&
                    <Input
                        type='text'
                        size='medium'
                        customClass='sb-header-feedname-input'
                        value={ editorFeedData.feedData.feed_info.feed_name }
                        onChange={ ( event ) => {
                            const newFeedName = event.currentTarget.value;
                            editorFeedData.setFeedData( {
                                ...editorFeedData.feedData,
                                feed_info : {
                                    ...editorFeedData.feedData.feed_info,
                                    feed_name : newFeedName
                                }
                            } )
                        } }
                        style={ { width : (( editorFeedData.feedData.feed_info.feed_name.length + 2 ) * 7) + 'px' } }
                    />
                    }
                    {
                        !editorActiveViews.activeViews?.headerInput &&
                        <span className="sb-bold sb-standard-p">{ editorFeedData.feedData.feed_info.feed_name }</span>
                    }
                    <Button
                        type='secondary'
                        size='small'
                        icon={ editorActiveViews.activeViews?.headerInput === true ? 'success' : 'pen'}
                        boxshadow={false}
                        customClass='sb-header-edit-btn'
                        onClick={ () => {
                            editorActiveViews.setActiveViews(
                                {
                                    ...editorActiveViews.activeViews,
                                    headerInput : !editorActiveViews.activeViews?.headerInput
                                }
                            )
                        }}
                    />
            </div>

            <div className='sb-header-action-btns'>
                <Button
                    type='secondary'
                    size='medium'
                    icon='help'
                    text={ __( 'Help', 'sb-customizer' ) }
                />
                <Button
                    type='secondary'
                    size='medium'
                    icon='code'
                    text={ __( 'Embed', 'sb-customizer' ) }
                    onClick={ () => {
                        saveUpdateHeader()
                        headerClasses.setHeaderCustomClasses([...headerClasses.headerCustomClasses, 'sb-header-zindex' ])
                        setEmbedModal( true )
                    } }
                />
                <Button
                    type='primary'
                    size='medium'
                    icon='success'
                    onClick={ () => {
                        saveUpdateHeader()
                    }}
                    text={ __( 'Save', 'sb-customizer' ) }
                />
            </div>
            {
                embedModal &&
                <ModalContainer
                    size='small'
                    closebutton={ true }
                    onClose={ () => {
                        setEmbedModal( false )
                        const cHeaderC = [...headerClasses.headerCustomClasses],
                            indexOfHeader = cHeaderC.indexOf('sb-header-zindex')
                            cHeaderC.splice(indexOfHeader, 1);
                        headerClasses.setHeaderCustomClasses([...cHeaderC])

                    } }
                >
                    <CustomizerEmbedModal
                        editorFeedData={ editorFeedData }
                        editorNotification={ editorNotification }
                        sbCustomizer= {sbCustomizer }

                    />
                </ModalContainer>
            }
        </>
    )
}

export default CustomizerHeader;