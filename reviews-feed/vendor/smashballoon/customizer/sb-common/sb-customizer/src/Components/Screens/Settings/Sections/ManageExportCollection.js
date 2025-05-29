import { useContext, useState } from "react";
import SbUtils from "../../../../Utils/SbUtils";
import Button from "../../../Common/Button";
import { __ } from "@wordpress/i18n";
import Select from "../../../Common/Select";

const ManageExportCollection = () => {
	const {
		sbSettings,
		editorTopLoader,
		editorNotification
	} = useContext( SbUtils.getCurrentContext() );

    const [selectedCollection, setSelectedCollection] = useState( false );

	const exportCollection = () => {
        if (selectedCollection !== "-1" && selectedCollection !== false) {
		 const formData = {
				action : 'sbr_feed_saver_manager_export_collection',
				collection_id : selectedCollection
			},
			notificationsContent = {
				success : {
					icon : 'success',
					text : __( 'Collection JSON exported!', 'sb-customizer' )
				}
			};
			SbUtils.ajaxPost(
				sbSettings.ajaxHandler,
				formData,
				( data ) => { //Call Back Function
                    if( data?.collection ){
                        SbUtils.exportStringToFile( JSON.stringify( data?.collection ) ,`sbr-${selectedCollection}.json` )
                    }
				},
				editorTopLoader,
				editorNotification,
				notificationsContent
			)

        }
    }

	return (
        <>
        <div className='sb-settings-input-ctn'>
            <Select
                value={ selectedCollection }
                size='medium'
                onChange={ ( event ) => {
                    setSelectedCollection( event.currentTarget.value )
                }}
            >
                <option value={false}>{  __( 'Select Feed', 'reviews-feed' ) } </option>
                {
                    sbSettings?.collectionsList.map( collection => {
                        return (
                            <option key={collection.account_id} value={collection.account_id}>{ collection.name }</option>
                        )
                    } )
                }
            </Select>
            <Button
                text={  __( 'Export', 'reviews-feed' ) }
                type='secondary'
                size='medium'
                icon='download'
                iconSize='11'
                onClick={ () => {
                    exportCollection()
                } }
            />
        </div>
        </>
    )
}
export default ManageExportCollection;